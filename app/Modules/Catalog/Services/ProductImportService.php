<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\InitialStockRecorder;
use App\Modules\Catalog\Exceptions\ProductImportRejectedException;
use App\Modules\Catalog\Imports\ProductsImport;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Support\ProductRules;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * The Excel entry point of the "contrat commun de création"
 * (docs/catalog.md §"Import Excel"): every row is normalized into the
 * same shape a manual CreateProductRequest produces, validated against
 * the exact same ProductRules, and persisted through the exact same
 * ProductService — no separate business logic for imported products.
 *
 * Transaction strategy (deliberate, see docs/catalog.md): NOT one giant
 * transaction for the whole file. Each valid row is committed as soon
 * as it's created, so a failure on row 400 never rolls back the 399
 * good ones already inserted — partial import with a per-row error
 * report, not all-or-nothing.
 */
class ProductImportService
{
    /** Past this many data rows, the file is rejected outright — not partially processed. */
    private const MAX_ROWS = 2000;

    public function __construct(
        private readonly ProductService $products,
        private readonly InitialStockRecorder $stock,
    ) {}

    /**
     * @return array{total_lignes: int, importes: int, rejetes: int, erreurs: array<int, array{ligne: int, erreurs: array<string, array<int, string>>}>}
     *
     * @throws ProductImportRejectedException the whole file is unreadable or has too many rows
     */
    public function import(UploadedFile $file, Store $store, ?int $userId = null): array
    {
        try {
            $sheets = Excel::toCollection(new ProductsImport, $file);
        } catch (Throwable $e) {
            throw new ProductImportRejectedException('Le fichier est illisible ou corrompu.', previous: $e);
        }

        $rows = $sheets->first() ?? collect();

        if ($rows->count() > self::MAX_ROWS) {
            throw new ProductImportRejectedException(
                sprintf('Le fichier contient %d lignes, au-delà de la limite autorisée (%d).', $rows->count(), self::MAX_ROWS)
            );
        }

        $imported = 0;
        $stocked = 0;
        $errors = [];
        // Colonnes stock_initial / stock_minimum : ignorées si la boutique ne suit pas le stock.
        $tracksStock = $this->stock->tracksStock($store);

        foreach ($rows as $index => $row) {
            $line = $index + 2; // 1 = header row

            [$data, $categoryError] = $this->normalizeRow($row->toArray(), $store);

            $stockData = $this->normalizeStock($row->toArray());
            $validator = Validator::make([...$data, ...$stockData], [
                ...ProductRules::rules(),
                'stock_initial' => ['nullable', 'numeric', 'min:0'],
                'stock_minimum' => ['nullable', 'numeric', 'min:0'],
            ]);
            $validator->fails();

            if ($categoryError !== null) {
                $validator->errors()->add('categorie', $categoryError);
            }

            if ($validator->errors()->isNotEmpty()) {
                $errors[] = ['ligne' => $line, 'erreurs' => $validator->errors()->toArray()];

                continue;
            }

            $validated = $validator->validated();
            $initial = $tracksStock ? ($validated['stock_initial'] ?? null) : null;
            unset($validated['stock_initial'], $validated['stock_minimum']);

            // Produit et stock initial ensemble ou pas du tout (transaction par ligne).
            DB::transaction(function () use ($validated, $initial, $stockData, $userId) {
                $product = $this->products->create($validated);
                if ($initial !== null) {
                    $this->stock->record($product, (string) $initial, $stockData['stock_minimum'], $userId, 'Stock initial (import Excel)');
                }
            });
            $imported++;
            $stocked += $initial !== null ? 1 : 0;
        }

        return [
            'total_lignes' => $rows->count(),
            'importes' => $imported,
            'stocks_initialises' => $stocked,
            'rejetes' => count($errors),
            'erreurs' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function normalizeRow(array $row, Store $store): array
    {
        $name = $this->nullableString($row['nom'] ?? null);
        $categoryName = $this->nullableString($row['categorie'] ?? null);

        $categoryId = null;
        $categoryError = null;

        if ($categoryName !== null) {
            // Tenant-aware on purpose — see docs/catalog.md §"Import par
            // nom de catégorie": never resolved globally, and never
            // auto-created when missing, the row is rejected instead.
            $category = Category::query()
                ->where('boutique_id', $store->id)
                ->whereRaw('LOWER(nom) = ?', [Str::lower($categoryName)])
                ->first();

            if ($category) {
                $categoryId = $category->id;
            } else {
                $categoryError = "La catégorie \"{$categoryName}\" est introuvable dans cette boutique.";
            }
        }

        $data = [
            'nom' => $name,
            'slug' => $name !== null ? Str::slug($name) : null,
            'description' => $this->nullableString($row['description'] ?? null),
            'categorie_id' => $categoryId,
            'sku' => $this->nullableString($row['sku'] ?? null),
            'code_barres' => $this->nullableString($row['code_barres'] ?? null),
            'unite' => $this->nullableString($row['unite'] ?? null) ?? 'piece',
            'prix_achat' => $this->nullableString($row['prix_achat'] ?? null),
            'vente_detail_active' => $this->normalizeBoolean($row['vente_detail_active'] ?? false),
            'prix_detail' => $this->nullableString($row['prix_detail'] ?? null),
            'vente_gros_active' => $this->normalizeBoolean($row['vente_gros_active'] ?? false),
            'prix_gros' => $this->nullableString($row['prix_gros'] ?? null),
            'actif' => $this->normalizeBoolean($row['actif'] ?? true),
        ];

        return [$data, $categoryError];
    }

    /** @return array{stock_initial: ?string, stock_minimum: ?string} */
    private function normalizeStock(array $row): array
    {
        return [
            'stock_initial' => $this->nullableString($row['stock_initial'] ?? null),
            'stock_minimum' => $this->nullableString($row['stock_minimum'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Accepts the common spreadsheet spellings of a boolean (real bool,
     * 1/0, "true"/"false", French "oui"/"non"/"vrai"/"faux"). Anything
     * else is passed through unchanged so the `boolean` validation rule
     * reports it as a clear per-row error instead of silently coercing it.
     */
    private function normalizeBoolean(mixed $value): mixed
    {
        if (is_bool($value) || $value === null) {
            return $value;
        }

        return match (Str::lower(trim((string) $value))) {
            'true', '1', 'oui', 'yes', 'vrai' => true,
            'false', '0', 'non', 'no', 'faux' => false,
            default => $value,
        };
    }
}
