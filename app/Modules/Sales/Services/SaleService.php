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
     *     items: array<int, array{product_id: int, pricing_mode: string, quantity: string|float|int}>,
     *     cash_register_id: int,
     *     customer_id?: int|null,
     *     payment_method?: string,
     *     discount_amount?: string|float|int|null,
     *     idempotency_key?: string|null,
     * }  $data
     */
    public function checkout(Store $store, array $data, ?int $userId): Sale
    {
        $idempotencyKey = $data['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            $existing = Sale::query()->where('idempotency_key', $idempotencyKey)->first();
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
                $existing = Sale::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            $register = CashRegister::query()->findOrFail($data['cash_register_id']);
            if ($register->open_session_id === null) {
                throw new NoOpenCashRegisterSessionException("La caisse \"{$register->name}\" n'a pas de session ouverte.");
            }
            $session = CashRegisterSession::query()->findOrFail($register->open_session_id);

            $lines = $this->priceLines($data['items']);
            $subtotal = $lines->reduce(fn (string $carry, array $line) => bcadd($carry, $line['total_amount'], 2), '0.00');

            $discount = Money::round((string) ($data['discount_amount'] ?? '0'));
            if (bccomp($discount, $subtotal, 2) > 0) {
                throw new InvalidDiscountException('La remise ne peut pas dépasser le sous-total.');
            }
            $total = bcsub($subtotal, $discount, 2);

            $sale = $this->createSale($register, $session, $data, $userId, $subtotal, $discount, $total, $idempotencyKey);

            foreach ($lines as $line) {
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $line['product']->id,
                    'product_name' => $line['product']->name,
                    'pricing_mode' => $line['mode'],
                    'unit_price' => $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'total_amount' => $line['total_amount'],
                ]);

                // Sorted by product_id in priceLines() before this loop
                // runs — a consistent lock order across every checkout,
                // so two concurrent carts sharing products can never
                // deadlock on Stock rows. See docs/sales.md §"Verrouillage".
                $this->inventory->removeStock($line['product'], StockMovementType::Sale, $line['quantity'], $userId, null, $sale);
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

    /** @return Collection<int, array{product: Product, mode: PricingMode, unit_price: string, quantity: string, total_amount: string}> */
    private function priceLines(array $items): Collection
    {
        $productIds = collect($items)->pluck('product_id')->unique()->values();
        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');

        return collect($items)
            ->sortBy('product_id') // see the lock-ordering note in checkout()
            ->values()
            ->map(function (array $item) use ($products) {
                /** @var Product $product */
                $product = $products->get($item['product_id']) ?? throw new InvalidArgumentException("Unknown product #{$item['product_id']}.");
                $mode = PricingMode::from($item['pricing_mode']);

                try {
                    $unitPrice = $product->priceFor($mode);
                } catch (InvalidArgumentException) {
                    throw PricingModeNotAvailableException::forProduct($product, $mode);
                }

                $quantity = (string) $item['quantity'];
                $lineTotal = Money::round(bcmul($unitPrice, $quantity, 6));

                return [
                    'product' => $product,
                    'mode' => $mode,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'total_amount' => $lineTotal,
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
            'cash_register_id' => $register->id,
            'cash_register_session_id' => $session->id,
            'customer_id' => $data['customer_id'] ?? null,
            'sold_by_user_id' => $userId,
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'total_amount' => $total,
            'status' => SaleStatus::Completed,
            'payment_method' => PaymentMethod::from($data['payment_method'] ?? PaymentMethod::Cash->value),
            'idempotency_key' => $idempotencyKey,
            'sold_at' => now(),
        ];

        try {
            $sale = Sale::create($attributes);
        } catch (QueryException $e) {
            if ($idempotencyKey !== null && $this->isUniqueConstraintViolation($e)) {
                // Lost a race on the idempotency key to a concurrent
                // request — return what it created instead of failing.
                return Sale::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
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
