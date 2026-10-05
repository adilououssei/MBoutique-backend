<?php

namespace App\Modules\Sales\Services;

use App\Modules\CashRegister\Exceptions\CashRegisterSessionClosedException;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Catalog\Enums\PricingMode;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerAccountService;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Exceptions\InvalidDepositException;
use App\Modules\Sales\Exceptions\InvalidDiscountException;
use App\Modules\Sales\Exceptions\NoOpenCashRegisterSessionException;
use App\Modules\Sales\Exceptions\PricingModeNotAvailableException;
use App\Modules\Sales\Exceptions\SaleAlreadyCancelledException;
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
        private readonly FeatureGate $features,
        private readonly CustomerAccountService $customerAccounts,
    ) {}

    /**
     * @param  array{
     *     lignes: array<int, array{produit_id?: int|null, service_id?: int|null, mode_prix?: string|null, quantite: string|float|int, remise?: string|float|int|null}>,
     *     caisse_id: int,
     *     client_id?: int|null,
     *     mode_paiement?: string,
     *     montant_remise?: string|float|int|null,
     *     acompte?: string|float|int|null,
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

        // A store without the `stock` feature (salon, atelier, « autre »…)
        // sells products without tracking quantities — docs/sales.md §23.
        $tracksStock = $this->features->allows($store, 'stock');

        return DB::transaction(function () use ($data, $userId, $idempotencyKey, $tracksStock) {
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

            // Vente à crédit : seul l'acompte entre en caisse, le reste va au
            // compte client — docs/sales.md §24.
            $onCredit = ($data['mode_paiement'] ?? null) === PaymentMethod::Credit->value;
            $paidNow = $total;
            if ($onCredit) {
                $paidNow = Money::round((string) ($data['acompte'] ?? '0'));
                if (bccomp($paidNow, $total, 2) > 0) {
                    throw new InvalidDepositException("L'acompte ne peut pas dépasser le total de la vente.");
                }
            }

            $sale = $this->createSale($register, $session, $data, $userId, $subtotal, $discount, $total, $idempotencyKey, $onCredit ? $paidNow : null);

            foreach ($lines as $line) {
                SaleItem::create([
                    'vente_id' => $sale->id,
                    'produit_id' => $line['product']?->id,
                    'service_id' => $line['service']?->id,
                    'nom_produit' => $line['nom'],
                    'mode_prix' => $line['mode'],
                    'prix_unitaire' => $line['prix_unitaire'],
                    'quantite' => $line['quantite'],
                    'montant_remise' => $line['montant_remise'],
                    'montant_total' => $line['montant_total'],
                ]);

                // A service line moves no stock. Product lines are sorted by
                // product_id in priceLines() — a consistent lock order across
                // every checkout, so two concurrent carts sharing products can
                // never deadlock on Stock rows. See docs/sales.md §"Verrouillage".
                if ($line['product'] !== null && $tracksStock) {
                    $this->inventory->removeStock($line['product'], StockMovementType::Sale, $line['quantite'], $userId, null, $sale);
                }
            }

            // Lock order: Inventory (above), then customer, then CashRegister.
            $owed = bcsub($total, $paidNow, 2);
            if (bccomp($owed, '0', 2) > 0) {
                $customer = Customer::query()->findOrFail($data['client_id']);
                $this->customerAccounts->recordCreditSale($customer, $owed, $userId, $sale);
            }

            try {
                if (! $onCredit || bccomp($paidNow, '0', 2) > 0) {
                    $this->cashRegisters->recordSale($session, $paidNow, $userId, $sale);
                }
            } catch (CashRegisterSessionClosedException $e) {
                // The session closed between our check above and here
                // (another request raced closeSession()) — surfaced as
                // the same business error a missing-session checkout gets.
                throw new NoOpenCashRegisterSessionException($e->getMessage(), previous: $e);
            }

            return $sale;
        });
    }

    /**
     * Cancels a completed sale as a whole: every product line goes back to
     * stock (ReturnIn) and the sale total is paid out of an open cash
     * session (Refund). One transaction — either everything is reversed or
     * nothing is. The original sale row is kept (status `annulee`), never
     * deleted: the receipt history stays intact. See docs/sales.md §20.
     *
     * @param  array{motif: string, caisse_id?: int|null}  $data
     */
    public function cancel(Sale $sale, array $data, ?int $userId): Sale
    {
        return DB::transaction(function () use ($sale, $data, $userId) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($sale->isCancelled()) {
                throw new SaleAlreadyCancelledException("La vente {$sale->reference} est déjà annulée.");
            }

            // Refund from the requested register, else from the sale's own —
            // either way it must have an open session right now.
            $register = CashRegister::query()->findOrFail($data['caisse_id'] ?? $sale->caisse_id);
            if ($register->session_ouverte_id === null) {
                throw new NoOpenCashRegisterSessionException(
                    "La caisse \"{$register->nom}\" n'a pas de session ouverte : ouvrez-la ou choisissez une autre caisse pour rembourser."
                );
            }
            $session = CashRegisterSession::query()->findOrFail($register->session_ouverte_id);
            $reason = "Annulation de la vente {$sale->reference} : {$data['motif']}";

            // Same lock order as checkout(): Inventory first (products sorted
            // by id), CashRegister last. withTrashed(): a product deleted
            // since the sale still gets its units back.
            $items = $sale->items()->whereNotNull('produit_id')->orderBy('produit_id')->get();
            $products = Product::withTrashed()->whereIn('id', $items->pluck('produit_id'))->get()->keyBy('id');
            foreach ($items as $item) {
                $product = $products[$item->produit_id];
                // Only what this sale actually took out goes back (nothing
                // if the store didn't track stock at sale time).
                if ($this->inventory->hasRemovedFor($product, $sale)) {
                    $this->inventory->addStock($product, StockMovementType::ReturnIn, (string) $item->quantite, $userId, $reason, $sale);
                }
            }

            // Vente à crédit : la part due sort du compte client, seul
            // l'acompte (ce qui est entré en caisse) est remboursé.
            $paidNow = (string) ($sale->montant_acompte ?? $sale->montant_total);
            $owed = bcsub((string) $sale->montant_total, $paidNow, 2);
            if (bccomp($owed, '0', 2) > 0) {
                $customer = Customer::withTrashed()->findOrFail($sale->client_id);
                $this->customerAccounts->reverseCreditSale($customer, $owed, $userId, $sale, $reason);
            }

            if (bccomp($paidNow, '0', 2) > 0) {
                try {
                    $this->cashRegisters->recordRefund($session, $paidNow, $userId, $sale, $reason);
                } catch (CashRegisterSessionClosedException $e) {
                    throw new NoOpenCashRegisterSessionException($e->getMessage(), previous: $e);
                }
            }

            $sale->update([
                'statut' => SaleStatus::Cancelled,
                'annulee_le' => now(),
                'annulee_par_id' => $userId,
                'motif_annulation' => $data['motif'],
                'session_remboursement_id' => $session->id,
            ]);

            return $sale;
        });
    }

    /**
     * Prices every line server-side (never trusting a client price) and
     * applies the per-line discount. Product lines come first, sorted by
     * product_id (lock order, see checkout()); service lines follow.
     *
     * @return Collection<int, array{product: ?Product, service: ?Service, nom: string, mode: ?PricingMode, prix_unitaire: string, quantite: string, montant_remise: string, montant_total: string}>
     */
    private function priceLines(array $items): Collection
    {
        $items = collect($items);
        $products = Product::query()->whereIn('id', $items->pluck('produit_id')->filter()->unique())->get()->keyBy('id');
        $services = Service::query()->whereIn('id', $items->pluck('service_id')->filter()->unique())->get()->keyBy('id');

        return $items
            ->sortBy(fn (array $item) => filled($item['produit_id'] ?? null) ? [0, (int) $item['produit_id']] : [1, (int) $item['service_id']])
            ->values()
            ->map(function (array $item) use ($products, $services) {
                $product = null;
                $service = null;
                $mode = null;

                if (filled($item['produit_id'] ?? null)) {
                    /** @var Product $product */
                    $product = $products->get($item['produit_id']) ?? throw new InvalidArgumentException("Produit #{$item['produit_id']} introuvable.");
                    $mode = PricingMode::from($item['mode_prix']);

                    try {
                        $unitPrice = $product->priceFor($mode);
                    } catch (InvalidArgumentException) {
                        throw PricingModeNotAvailableException::forProduct($product, $mode);
                    }
                } else {
                    /** @var Service $service */
                    $service = $services->get($item['service_id']) ?? throw new InvalidArgumentException("Service #{$item['service_id']} introuvable.");
                    $unitPrice = Money::round((string) $service->prix);
                }

                $quantity = (string) $item['quantite'];
                $gross = Money::round(bcmul($unitPrice, $quantity, 6));
                $lineDiscount = Money::round((string) ($item['remise'] ?? '0'));
                $name = $product?->nom ?? $service->nom;

                if (bccomp($lineDiscount, $gross, 2) > 0) {
                    throw new InvalidDiscountException("La remise sur \"{$name}\" dépasse le montant de la ligne.");
                }

                return [
                    'product' => $product,
                    'service' => $service,
                    'nom' => $name,
                    'mode' => $mode,
                    'prix_unitaire' => $unitPrice,
                    'quantite' => $quantity,
                    'montant_remise' => $lineDiscount,
                    'montant_total' => bcsub($gross, $lineDiscount, 2),
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
        ?string $deposit = null,
    ): Sale {
        $attributes = [
            'caisse_id' => $register->id,
            'session_caisse_id' => $session->id,
            'client_id' => $data['client_id'] ?? null,
            'vendeur_id' => $userId,
            'sous_total' => $subtotal,
            'montant_remise' => $discount,
            'montant_total' => $total,
            'montant_acompte' => $deposit,
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
