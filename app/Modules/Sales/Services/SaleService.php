<?php

namespace App\Modules\Sales\Services;

use App\Modules\CashRegister\Exceptions\CashRegisterSessionClosedException;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Catalog\Enums\PricingMode;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Exceptions\InvalidDiscountException;
use App\Modules\Sales\Exceptions\NoOpenCashRegisterSessionException;
use App\Modules\Sales\Exceptions\PricingModeNotAvailableException;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sales\Support\Money;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only place a Sale is ever created — see docs/sales.md
 * §"Checkout". Orchestrates InventoryService and CashRegisterService
 * inside one outer transaction; both are called through their own
 * public contract, never by writing to `stocks`/`cash_movements` directly
 * (Phase 4.3 brief §40).
 */
class SaleService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly CashRegisterService $cashRegisters,
    ) {}

    /**
     * @param  array{
     *     lignes: array<int, array{produit_id: int, mode_prix: string, quantite: string|float|int}>,
     *     caisse_id: int,
     *     client_id?: int|null,
     *     mode_paiement?: string,
     *     montant_remise?: string|float|int|null,
     *     cle_idempotence?: string|null,
     * }  $data
     */
    public function checkout(Store $store, array $data, ?int $userId): Sale
    {
        $idempotencyKey = $data['cle_idempotence'] ?? null;

        if ($idempotencyKey !== null) {
            $existing = Sale::query()->where('cle_idempotence', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($data, $userId, $idempotencyKey) {
            // Re-check under the transaction to close the race between
            // the check above and this one — the (store_id,
            // idempotency_key) unique index is the real guarantee
            // (caught below), this closure just makes the common case fast.
            if ($idempotencyKey !== null) {
                $existing = Sale::query()->where('cle_idempotence', $idempotencyKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            $register = CashRegister::query()->findOrFail($data['caisse_id']);
            if ($register->session_ouverte_id === null) {
                throw new NoOpenCashRegisterSessionException("La caisse \"{$register->nom}\" n'a pas de session ouverte.");
            }
            $session = CashRegisterSession::query()->findOrFail($register->session_ouverte_id);

            $lines = $this->priceLines($data['lignes']);
            $subtotal = $lines->reduce(fn (string $carry, array $line) => bcadd($carry, $line['montant_total'], 2), '0.00');

            $discount = Money::round((string) ($data['montant_remise'] ?? '0'));
            if (bccomp($discount, $subtotal, 2) > 0) {
                throw new InvalidDiscountException('La remise ne peut pas dépasser le sous-total.');
            }
            $total = bcsub($subtotal, $discount, 2);

            $sale = $this->createSale($register, $session, $data, $userId, $subtotal, $discount, $total, $idempotencyKey);

            foreach ($lines as $line) {
                SaleItem::create([
                    'vente_id' => $sale->id,
                    'produit_id' => $line['product']->id,
                    'nom_produit' => $line['product']->nom,
                    'mode_prix' => $line['mode'],
                    'prix_unitaire' => $line['prix_unitaire'],
                    'quantite' => $line['quantite'],
                    'montant_total' => $line['montant_total'],
                ]);

                // Sorted by product_id in priceLines() before this loop
                // runs — a consistent lock order across every checkout,
                // so two concurrent carts sharing products can never
                // deadlock on Stock rows. See docs/sales.md §"Verrouillage".
                $this->inventory->removeStock($line['product'], StockMovementType::Sale, $line['quantite'], $userId, null, $sale);
            }

            try {
                $this->cashRegisters->recordSale($session, $total, $userId, $sale);
            } catch (CashRegisterSessionClosedException $e) {
                // The session closed between our check above and here
                // (another request raced closeSession()) — surfaced as
                // the same business error a missing-session checkout gets.
                throw new NoOpenCashRegisterSessionException($e->getMessage(), previous: $e);
            }

            return $sale;
        });
    }

    /** @return Collection<int, array{product: Product, mode: PricingMode, prix_unitaire: string, quantite: string, montant_total: string}> */
    private function priceLines(array $items): Collection
    {
        $productIds = collect($items)->pluck('produit_id')->unique()->values();
        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');

        return collect($items)
            ->sortBy('produit_id') // see the lock-ordering note in checkout()
            ->values()
            ->map(function (array $item) use ($products) {
                /** @var Product $product */
                $product = $products->get($item['produit_id']) ?? throw new InvalidArgumentException("Produit #{$item['produit_id']} introuvable.");
                $mode = PricingMode::from($item['mode_prix']);

                try {
                    $unitPrice = $product->priceFor($mode);
                } catch (InvalidArgumentException) {
                    throw PricingModeNotAvailableException::forProduct($product, $mode);
                }

                $quantity = (string) $item['quantite'];
                $lineTotal = Money::round(bcmul($unitPrice, $quantity, 6));

                return [
                    'product' => $product,
                    'mode' => $mode,
                    'prix_unitaire' => $unitPrice,
                    'quantite' => $quantity,
                    'montant_total' => $lineTotal,
                ];
            });
    }

    private function createSale(
        CashRegister $register,
        CashRegisterSession $session,
        array $data,
        ?int $userId,
        string $subtotal,
        string $discount,
        string $total,
        ?string $idempotencyKey,
    ): Sale {
        $attributes = [
            'caisse_id' => $register->id,
            'session_caisse_id' => $session->id,
            'client_id' => $data['client_id'] ?? null,
            'vendeur_id' => $userId,
            'sous_total' => $subtotal,
            'montant_remise' => $discount,
            'montant_total' => $total,
            'statut' => SaleStatus::Completed,
            'mode_paiement' => PaymentMethod::from($data['mode_paiement'] ?? PaymentMethod::Cash->value),
            'cle_idempotence' => $idempotencyKey,
            'vendue_le' => now(),
        ];

        try {
            $sale = Sale::create($attributes);
        } catch (QueryException $e) {
            if ($idempotencyKey !== null && $this->isUniqueConstraintViolation($e)) {
                // Lost a race on the idempotency key to a concurrent
                // request — return what it created instead of failing.
                return Sale::query()->where('cle_idempotence', $idempotencyKey)->firstOrFail();
            }
            throw $e;
        }

        // reference needs the row's own id, so it's filled in right
        // after insert, inside the same transaction — see docs/sales.md §"Référence".
        $sale->update(['reference' => sprintf('VTE-%s-%06d', now()->format('Ymd'), $sale->id)]);

        return $sale;
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23000';
    }
}
