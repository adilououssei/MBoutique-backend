<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Exceptions\ProductImportRejectedException;
use App\Modules\Catalog\Imports\ProductsImport;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Support\ProductRules;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Http\UploadedFile;
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

    public function __construct(private readonly ProductService $products) {}

    /**
     * @return array{total_rows: int, imported: int, rejected: int, errors: array<int, array{line: int, errors: array<string, array<int, string>>}>}
     *
     * @throws ProductImportRejectedException the whole file is unreadable or has too many rows
     */
    public function import(UploadedFile $file, Store $store): array
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
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2; // 1 = header row

            [$data, $categoryError] = $this->normalizeRow($row->toArray(), $store);

            $validator = Validator::make($data, ProductRules::rules());
            $validator->fails();

            if ($categoryError !== null) {
                $validator->errors()->add('category', $categoryError);
            }

            if ($validator->errors()->isNotEmpty()) {
                $errors[] = ['line' => $line, 'errors' => $validator->errors()->toArray()];

                continue;
            }

            $this->products->create($validator->validated());
            $imported++;
        }

        return [
            'total_rows' => $rows->count(),
            'imported' => $imported,
            'rejected' => count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function normalizeRow(array $row, Store $store): array
    {
        $name = $this->nullableString($row['name'] ?? null);
        $categoryName = $this->nullableString($row['category'] ?? null);

        $categoryId = null;
        $categoryError = null;

        if ($categoryName !== null) {
            // Tenant-aware on purpose — see docs/catalog.md §"Import par
            // nom de catégorie": never resolved globally, and never
            // auto-created when missing, the row is rejected instead.
            $category = Category::query()
                ->where('store_id', $store->id)
                ->whereRaw('LOWER(name) = ?', [Str::lower($categoryName)])
                ->first();

            if ($category) {
                $categoryId = $category->id;
            } else {
                $categoryError = "La catégorie \"{$categoryName}\" est introuvable dans cette boutique.";
            }
        }

        $data = [
            'name' => $name,
            'slug' => $name !== null ? Str::slug($name) : null,
            'description' => $this->nullableString($row['description'] ?? null),
            'category_id' => $categoryId,
            'sku' => $this->nullableString($row['sku'] ?? null),
            'barcode' => $this->nullableString($row['barcode'] ?? null),
            'unit' => $this->nullableString($row['unit'] ?? null) ?? 'piece',
            'purchase_price' => $this->nullableString($row['purchase_price'] ?? null),
            'retail_enabled' => $this->normalizeBoolean($row['retail_enabled'] ?? false),
            'retail_price' => $this->nullableString($row['retail_price'] ?? null),
            'wholesale_enabled' => $this->normalizeBoolean($row['wholesale_enabled'] ?? false),
            'wholesale_price' => $this->nullableString($row['wholesale_price'] ?? null),
            'is_active' => $this->normalizeBoolean($row['is_active'] ?? true),
        ];

        return [$data, $categoryError];
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
