<?php

namespace App\Modules\Suppliers\Services;

use App\Modules\CashRegister\Exceptions\CashRegisterSessionClosedException;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Devices\Services\DeviceService;
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
        private readonly DeviceService $devices,
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
                    'appareils' => (array) ($line['appareils'] ?? []),
                    'numero_lot' => $line['numero_lot'] ?? null,
                    'date_peremption' => $line['date_peremption'] ?? null,
                    'fabrique_le' => $line['fabrique_le'] ?? null,
                ]);
            $products = Product::query()->whereIn('id', $lines->pluck('produit_id'))->get()->keyBy('id');
            $lines = $lines->map(fn (array $line) => [
                ...$line,
                'montant_total' => Money::round(bcmul($line['cout_unitaire'], $line['quantite'], 6)),
            ]);
            $total = $lines->reduce(fn (string $carry, array $line) => bcadd($carry, $line['montant_total'], 2), '0.00');
            $isOrder = (bool) ($data['commande'] ?? false);

            // Frais annexes (transport, douane…) répartis au prorata de la valeur
            // des lignes : coût de revient unitaire. Le dernier prend l'arrondi.
            // Remise fournisseur : un frais négatif, répartie de la même façon.
            $extra = bcsub(Money::round((string) ($data['frais_annexes'] ?? '0')), Money::round((string) ($data['remise_fournisseur'] ?? '0')), 2);
            $allocated = '0.00';
            $last = $lines->count() - 1;
            $lines = $lines->values()->map(function (array $line, int $i) use ($extra, $total, &$allocated, $last) {
                if (bccomp($extra, '0', 2) === 0) {
                    return [...$line, 'cout_revient' => $line['cout_unitaire']];
                }
                $share = $i === $last ? bcsub($extra, $allocated, 2) : (bccomp($total, '0', 2) > 0 ? Money::round(bcdiv(bcmul($extra, $line['montant_total'], 8), $total, 8)) : '0.00');
                $allocated = bcadd($allocated, $share, 2);

                return [...$line, 'cout_revient' => Money::round(bcadd($line['cout_unitaire'], bcdiv($share, $line['quantite'], 8), 8))];
            });

            $purchase = $this->createPurchase($data, bcsub($total, Money::round((string) ($data['remise_fournisseur'] ?? '0')), 2), $userId, $idempotencyKey, $isOrder);
            if (! $purchase->wasRecentlyCreated) {
                return $purchase; // perdu une course sur la clé d'idempotence
            }

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $products[$line['produit_id']];

                $item = PurchaseItem::create([
                    'achat_id' => $purchase->id,
                    'produit_id' => $product->id,
                    'nom_produit' => $product->nom,
                    'quantite' => $line['quantite'],
                    'cout_unitaire' => $line['cout_unitaire'],
                    'cout_revient_unitaire' => $line['cout_revient'],
                    'montant_total' => $line['montant_total'],
                    'quantite_commandee' => $isOrder ? $line['quantite'] : null,
                    'numero_lot' => $line['numero_lot'],
                    'date_peremption' => $line['date_peremption'],
                    'fabrique_le' => $line['fabrique_le'],
                ]);

                if ($isOrder) {
                    continue; // Commande : stock et prix d'achat à la réception.
                }

                if ($tracksStock) {
                    $this->enterStock($product, $line['quantite'], $purchase, $userId);
                }

                // Appareils suivis à l'unité : un identifiant par unité reçue (facultatif ici).
                if ($tracksStock && $this->devices->tracks($product)) {
                    $this->devices->receive($product, $line['quantite'], $line['appareils'], $item, $line['cout_revient'], $userId);
                }

                if ($data['mettre_a_jour_prix_achat'] ?? true) {
                    $product->update(['prix_achat' => $line['cout_revient']]);
                }
            }

            $payment = $isOrder ? null : ($data['paiement'] ?? null);
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
        if ($purchase->isOrder()) {
            throw new InvalidPurchasePaymentException('Cette commande n’est pas encore reçue : réglez-la après la réception.');
        }
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

    /**
     * Réception d'une commande : quantités réellement reçues (0 = non livré),
     * lots et péremptions vérifiés ligne par ligne, puis entrée en stock et
     * dette fournisseur. Le total et le coût de revient sont recalculés.
     *
     * @param  array{lignes: array<int, array{ligne_id: int, quantite: string|float|int, numero_lot?: string|null, date_peremption?: string|null, fabrique_le?: string|null, cout_unitaire?: string|float|int|null}>, numero_facture_fournisseur?: string|null}  $data
     */
    public function receive(Store $store, Purchase $purchase, array $data, ?int $userId): Purchase
    {
        $tracksStock = $this->features->allows($store, 'stock');

        return DB::transaction(function () use ($purchase, $data, $userId, $tracksStock) {
            $purchase = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if (! $purchase->isOrder()) {
                throw new InvalidPurchasePaymentException('Cette commande est déjà reçue.');
            }
            $received = collect($data['lignes'])->keyBy('ligne_id');
            $items = $purchase->items()->orderBy('produit_id')->get();
            foreach ($items as $item) {
                $line = $received->get($item->id);
                $quantity = $line !== null ? (string) $line['quantite'] : '0';
                $cost = Money::round((string) ($line['cout_unitaire'] ?? $item->cout_unitaire));
                $item->update([
                    'quantite' => $quantity,
                    'cout_unitaire' => $cost,
                    'montant_total' => Money::round(bcmul($cost, $quantity, 6)),
                    'numero_lot' => $line['numero_lot'] ?? $item->numero_lot,
                    'date_peremption' => $line['date_peremption'] ?? $item->date_peremption,
                    'fabrique_le' => $line['fabrique_le'] ?? $item->fabrique_le,
                ]);
            }
            $total = $items->reduce(fn (string $c, PurchaseItem $i) => bcadd($c, (string) $i->montant_total, 2), '0.00');
            $extra = bcsub((string) $purchase->frais_annexes, (string) $purchase->remise_fournisseur, 2);
            $allocated = '0.00';
            $withQuantity = $items->filter(fn (PurchaseItem $i) => bccomp((string) $i->quantite, '0', 3) > 0)->values();
            foreach ($withQuantity as $i => $item) {
                $share = $i === $withQuantity->count() - 1 ? bcsub($extra, $allocated, 2) : (bccomp($total, '0', 2) > 0 ? Money::round(bcdiv(bcmul($extra, (string) $item->montant_total, 8), $total, 8)) : '0.00');
                $allocated = bcadd($allocated, $share, 2);
                $item->update(['cout_revient_unitaire' => Money::round(bcadd((string) $item->cout_unitaire, bcdiv($share, (string) $item->quantite, 8), 8))]);
            }

            $purchase->update([
                'statut' => 'recu',
                'montant_total' => bcsub($total, (string) $purchase->remise_fournisseur, 2),
                'achete_le' => now(),
                'recu_par_id' => $userId,
                'numero_facture_fournisseur' => $data['numero_facture_fournisseur'] ?? $purchase->numero_facture_fournisseur,
            ]);
            foreach ($withQuantity as $item) {
                $product = Product::query()->findOrFail($item->produit_id);
                if ($tracksStock) {
                    $this->enterStock($product, (string) $item->quantite, $purchase, $userId);
                }
                $product->update(['prix_achat' => $item->cout_revient_unitaire]);
            }

            return $purchase->refresh();
        });
    }

    /** Premier arrivage d'un produit jamais stocké (ex. importé depuis Excel) : l'achat initialise son stock. */
    private function enterStock(Product $product, string $quantity, Purchase $purchase, ?int $userId): void
    {
        $this->inventory->isInitialized($product)
            ? $this->inventory->addStock($product, StockMovementType::Purchase, $quantity, $userId, "Achat {$purchase->reference}", $purchase)
            : $this->inventory->initializeStock($product, $quantity, null, $userId, "Stock initialisé par l'achat {$purchase->reference}", $purchase);
    }

    private function createPurchase(array $data, string $total, ?int $userId, ?string $idempotencyKey, bool $isOrder = false): Purchase
    {
        try {
            $purchase = Purchase::create([
                'fournisseur_id' => $data['fournisseur_id'] ?? null,
                'numero_facture_fournisseur' => $data['numero_facture_fournisseur'] ?? null,
                'reference_commande' => $data['reference_commande'] ?? null,
                'frais_annexes' => Money::round((string) ($data['frais_annexes'] ?? '0')),
                'remise_fournisseur' => Money::round((string) ($data['remise_fournisseur'] ?? '0')),
                'statut' => $isOrder ? 'commande' : 'recu',
                'commande_le' => $isOrder ? now() : null,
                'detail_frais' => $data['detail_frais'] ?? null,
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
