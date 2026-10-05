<?php

namespace App\Modules\Suppliers\Services;

use App\Modules\CashRegister\Exceptions\CashRegisterSessionClosedException;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Support\Money;
use App\Modules\Suppliers\Enums\PurchasePaymentMode;
use App\Modules\Suppliers\Exceptions\CashRegisterNotOpenException;
use App\Modules\Suppliers\Exceptions\InvalidPurchasePaymentException;
use App\Modules\Suppliers\Models\Purchase;
use App\Modules\Suppliers\Models\PurchaseItem;
use App\Modules\Suppliers\Models\PurchasePayment;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'écriture des achats — docs/modules.md §Suppliers. Orchestre Inventory
 * (entrée en stock) et CashRegister (règlement depuis la caisse) via leurs
 * services publics, jamais en écrivant leurs tables directement, comme
 * SaleService. Ordre des verrous identique : Inventory puis CashRegister.
 */
class PurchaseService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly CashRegisterService $cashRegisters,
        private readonly FeatureGate $features,
    ) {}

    /**
     * @param  array{
     *     fournisseur_id?: int|null,
     *     lignes: array<int, array{produit_id: int, quantite: string|float|int, cout_unitaire: string|float|int}>,
     *     note?: string|null,
     *     achete_le?: string|null,
     *     mettre_a_jour_prix_achat?: bool,
     *     paiement?: array{montant: string|float|int, mode: string, caisse_id?: int|null}|null,
     *     cle_idempotence?: string|null,
     * }  $data
     */
    public function create(Store $store, array $data, ?int $userId): Purchase
    {
        $idempotencyKey = $data['cle_idempotence'] ?? null;
        if ($idempotencyKey !== null && ($existing = Purchase::query()->where('cle_idempotence', $idempotencyKey)->first())) {
            return $existing;
        }

        // Boutique sans gestion de stock : l'achat est enregistré (dépense,
        // dette fournisseur) sans mouvement de quantité — comme Sales.
        $tracksStock = $this->features->allows($store, 'stock');

        return DB::transaction(function () use ($data, $userId, $idempotencyKey, $tracksStock) {
            $lines = collect($data['lignes'])
                ->sortBy('produit_id')
                ->values()
                ->map(fn (array $line) => [
                    'produit_id' => (int) $line['produit_id'],
                    'quantite' => (string) $line['quantite'],
                    'cout_unitaire' => Money::round((string) $line['cout_unitaire']),
                ]);
            $products = Product::query()->whereIn('id', $lines->pluck('produit_id'))->get()->keyBy('id');
            $lines = $lines->map(fn (array $line) => [
                ...$line,
                'montant_total' => Money::round(bcmul($line['cout_unitaire'], $line['quantite'], 6)),
            ]);
            $total = $lines->reduce(fn (string $carry, array $line) => bcadd($carry, $line['montant_total'], 2), '0.00');

            $purchase = $this->createPurchase($data, $total, $userId, $idempotencyKey);
            if (! $purchase->wasRecentlyCreated) {
                return $purchase; // perdu une course sur la clé d'idempotence
            }

            $reason = "Achat {$purchase->reference}";
            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $products[$line['produit_id']];

                PurchaseItem::create([
                    'achat_id' => $purchase->id,
                    'produit_id' => $product->id,
                    'nom_produit' => $product->nom,
                    'quantite' => $line['quantite'],
                    'cout_unitaire' => $line['cout_unitaire'],
                    'montant_total' => $line['montant_total'],
                ]);

                if ($tracksStock) {
                    // Premier arrivage d'un produit jamais stocké (ex. importé
                    // depuis Excel) : l'achat initialise son stock.
                    $this->inventory->isInitialized($product)
                        ? $this->inventory->addStock($product, StockMovementType::Purchase, $line['quantite'], $userId, $reason, $purchase)
                        : $this->inventory->initializeStock($product, $line['quantite'], null, $userId, "Stock initialisé par l'achat {$purchase->reference}", $purchase);
                }

                if ($data['mettre_a_jour_prix_achat'] ?? true) {
                    $product->update(['prix_achat' => $line['cout_unitaire']]);
                }
            }

            $payment = $data['paiement'] ?? null;
            if ($payment !== null && bccomp(Money::round((string) $payment['montant']), '0', 2) > 0) {
                $this->recordPayment($purchase, $payment, $userId);
            }

            return $purchase->refresh();
        });
    }

    /**
     * Règlement (total ou partiel) d'un achat.
     *
     * @param  array{montant: string|float|int, mode: string, caisse_id?: int|null, note?: string|null}  $data
     */
    public function pay(Purchase $purchase, array $data, ?int $userId): PurchasePayment
    {
        return DB::transaction(fn () => $this->recordPayment($purchase, $data, $userId));
    }

    /** @param  array{montant: string|float|int, mode: string, caisse_id?: int|null, note?: string|null}  $data */
    private function recordPayment(Purchase $purchase, array $data, ?int $userId): PurchasePayment
    {
        $purchase = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
        $amount = Money::round((string) $data['montant']);

        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidPurchasePaymentException('Le montant du règlement doit être supérieur à zéro.');
        }
        if (bccomp($amount, $purchase->amountDue(), 2) > 0) {
            throw new InvalidPurchasePaymentException("Le règlement dépasse le reste à payer ({$purchase->amountDue()}).");
        }

        $mode = PurchasePaymentMode::from($data['mode']);
        $sessionId = null;

        if ($mode === PurchasePaymentMode::CashRegister) {
            $register = CashRegister::query()->findOrFail($data['caisse_id']);
            if ($register->session_ouverte_id === null) {
                throw new CashRegisterNotOpenException("La caisse \"{$register->nom}\" n'a pas de session ouverte.");
            }
            $session = CashRegisterSession::query()->findOrFail($register->session_ouverte_id);

            try {
                $this->cashRegisters->recordExpense($session, $amount, $userId, $purchase, "Règlement de l'achat {$purchase->reference}");
            } catch (CashRegisterSessionClosedException $e) {
                throw new CashRegisterNotOpenException($e->getMessage(), previous: $e);
            }
            $sessionId = $session->id;
        }

        $payment = PurchasePayment::create([
            'achat_id' => $purchase->id,
            'montant' => $amount,
            'mode' => $mode,
            'session_caisse_id' => $sessionId,
            'note' => $data['note'] ?? null,
            'paye_le' => now(),
            'cree_par_id' => $userId,
        ]);

        $purchase->update(['montant_paye' => bcadd((string) $purchase->montant_paye, $amount, 2)]);

        return $payment;
    }

    private function createPurchase(array $data, string $total, ?int $userId, ?string $idempotencyKey): Purchase
    {
        try {
            $purchase = Purchase::create([
                'fournisseur_id' => $data['fournisseur_id'] ?? null,
                'montant_total' => $total,
                'montant_paye' => '0.00',
                'note' => $data['note'] ?? null,
                'cle_idempotence' => $idempotencyKey,
                'achete_le' => $data['achete_le'] ?? now(),
                'cree_par_id' => $userId,
            ]);
        } catch (QueryException $e) {
            if ($idempotencyKey !== null && ($e->errorInfo[0] ?? null) === '23000') {
                return Purchase::query()->where('cle_idempotence', $idempotencyKey)->firstOrFail();
            }
            throw $e;
        }

        $purchase->update(['reference' => sprintf('ACH-%s-%06d', now()->format('Ymd'), $purchase->id)]);

        return $purchase;
    }
}
