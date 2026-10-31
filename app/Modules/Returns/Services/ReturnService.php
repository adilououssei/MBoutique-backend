<?php

namespace App\Modules\Returns\Services;

use App\Modules\CashRegister\Exceptions\CashRegisterSessionClosedException;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerAccountService;
use App\Modules\Devices\Services\DeviceService;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Inventory\Enums\LossOrigin;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Returns\Exceptions\ReturnException;
use App\Modules\Returns\Models\SaleReturn;
use App\Modules\Returns\Models\SaleReturnLine;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Sales\Support\Money;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Warranties\Services\WarrantyService;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'écriture des retours — docs/modules.md §Returns. Retour partiel
 * d'une vente, rattaché à elle : stock (revendable ou défectueux), appareils,
 * garanties, puis remboursement (caisse ou hors caisse) ou avoir client.
 */
class ReturnService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly CashRegisterService $cashRegisters,
        private readonly CustomerAccountService $accounts,
        private readonly DeviceService $devices,
        private readonly WarrantyService $warranties,
        private readonly FeatureGate $features,
    ) {}

    /**
     * @param  array{lignes: array<int, array{ligne_vente_id: int, quantite: string|float|int, appareil_ids?: array<int, int>}>, motif: string, decision: string, etat_produits: string, mode_remboursement?: string|null, caisse_id?: int|null}  $data
     */
    public function record(Sale $sale, array $data, ?int $userId): SaleReturn
    {
        return DB::transaction(function () use ($sale, $data, $userId) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->isCancelled()) {
                throw new ReturnException("La vente {$sale->reference} est annulée : rien à retourner.", 'VENTE_ANNULEE');
            }
            $defective = $data['etat_produits'] === 'defectueux';
            $quarantine = $data['etat_produits'] === 'a_verifier';
            $credit = $data['decision'] === 'avoir';
            if ($credit && $sale->client_id === null) {
                throw new ReturnException('Un avoir se fait sur le compte d’un client : cette vente n’en a pas. Choisissez le remboursement.', 'CLIENT_REQUIS');
            }

            $items = SaleItem::query()->where('vente_id', $sale->id)->whereNotNull('produit_id')->with('product')->get()->keyBy('id');
            // Remise globale de la vente répartie au prorata.
            $ratio = bccomp((string) $sale->sous_total, '0', 2) > 0 ? bcdiv((string) $sale->montant_total, (string) $sale->sous_total, 8) : '1';
            $prepared = [];
            $total = '0.00';
            foreach ($data['lignes'] as $line) {
                $item = $items->get((int) $line['ligne_vente_id']) ?? throw new ReturnException('Une ligne retournée n’appartient pas à cette vente (ou est un service).', 'LIGNE_INVALIDE');
                $quantity = bcadd((string) $line['quantite'], '0', 3);
                $already = (string) SaleReturnLine::query()->where('ligne_vente_id', $item->id)->sum('quantite');
                if (bccomp(bcadd($already, $quantity, 3), (string) $item->quantite, 3) > 0) {
                    throw new ReturnException("« {$item->nom_produit} » : on ne peut pas retourner plus que vendu (".rtrim(rtrim(bcsub((string) $item->quantite, $already, 3), '0'), '.').' restant).', 'QUANTITE_RETOUR_INVALIDE');
                }
                $amount = Money::round(bcmul(bcmul(bcdiv((string) $item->montant_total, (string) $item->quantite, 8), $quantity, 8), $ratio, 8));
                $total = bcadd($total, $amount, 2);
                $prepared[] = [$item, $quantity, $amount, array_map('intval', $line['appareil_ids'] ?? [])];
            }

            $return = SaleReturn::create([
                'vente_id' => $sale->id,
                'client_id' => $sale->client_id,
                'motif' => $data['motif'],
                'decision' => $data['decision'],
                'etat_produits' => $data['etat_produits'],
                'statut_verification' => $data['etat_produits'] === 'a_verifier' ? 'a_verifier' : null,
                'montant' => $total,
                'mode_remboursement' => $data['decision'] === 'remboursement' ? ($data['mode_remboursement'] ?? 'especes') : null,
                'cree_par_id' => $userId,
            ]);
            $return->update(['numero' => sprintf('RET-%s-%06d', now()->format('Ymd'), $return->id)]);

            $store = Store::query()->findOrFail($sale->boutique_id);
            $tracksStock = $this->features->allows($store, 'stock');
            $reason = "Retour {$return->numero} ({$sale->reference}) : {$data['motif']}";
            foreach ($prepared as [$item, $quantity, $amount, $deviceIds]) {
                $product = $item->product;
                if ($this->devices->tracks($product)) {
                    if (count($deviceIds) !== (int) $quantity) {
                        throw new ReturnException("« {$item->nom_produit} » est suivi à l’unité : indiquez les appareils retournés.", 'APPAREILS_REQUIS');
                    }
                    $this->devices->returnFromSale($item, $deviceIds, $defective, $return, $userId);
                }
                $stockQuantity = $this->features->allows($store, 'conditionnement') ? $product->stockQuantityFor($item->mode_prix, $quantity) : $quantity;
                // Pharmacie : un produit « à vérifier » reste en quarantaine, hors stock, jusqu'à la décision.
                if ($tracksStock && ! $quarantine && $this->inventory->isInitialized($product)) {
                    // Retour tracé ; un produit défectueux ressort aussitôt (perte valorisée) : il n'est pas revendable.
                    $this->inventory->addStock($product, StockMovementType::ReturnIn, $stockQuantity, $userId, $reason, $return);
                    if ($defective) {
                        $this->inventory->removeStock($product, StockMovementType::Loss, $stockQuantity, $userId, $reason, $return, LossOrigin::Spoiled);
                    }
                }
                $fullyReturned = bccomp((string) SaleReturnLine::query()->where('ligne_vente_id', $item->id)->sum('quantite'), bcsub((string) $item->quantite, $quantity, 3), 3) >= 0;
                if ($deviceIds !== [] || $fullyReturned) {
                    $this->warranties->cancelFor($item->id, $deviceIds);
                }
                $return->lines()->create(['ligne_vente_id' => $item->id, 'produit_id' => $item->produit_id, 'nom_produit' => $item->nom_produit, 'quantite' => $quantity, 'montant' => $amount, 'appareil_ids' => $deviceIds ?: null]);
            }

            if ($credit) {
                $this->accounts->recordReturnCredit(Customer::withTrashed()->findOrFail($sale->client_id), $total, $userId, $return, "Avoir du retour {$return->numero}");
            } elseif ($data['decision'] === 'remboursement' && ($data['mode_remboursement'] ?? 'especes') === 'especes' && bccomp($total, '0', 2) > 0) {
                $register = CashRegister::query()->findOrFail($data['caisse_id'] ?? $sale->caisse_id);
                if ($register->session_ouverte_id === null) {
                    throw new ReturnException("La caisse « {$register->nom} » n’a pas de session ouverte pour rembourser.", 'AUCUNE_SESSION_CAISSE_OUVERTE');
                }
                $session = CashRegisterSession::query()->findOrFail($register->session_ouverte_id);
                try {
                    $this->cashRegisters->recordRefund($session, $total, $userId, $return, $reason);
                } catch (CashRegisterSessionClosedException $e) {
                    throw new ReturnException($e->getMessage(), 'AUCUNE_SESSION_CAISSE_OUVERTE');
                }
                $return->update(['session_caisse_id' => $session->id]);
            }

            return $return;
        });
    }

    /**
     * Échange en une opération : les articles rendus entrent en stock (retour
     * `echange`, lié à la vente d'origine), les nouveaux sortent par une vente
     * (lien `vente_echange_id`) dont la valeur rendue est déjà réglée. Le
     * client paie la différence (moyen choisi) ; si le nouvel article coûte
     * moins, l'écart lui est rendu en espèces ou crédité en avoir.
     *
     * @param  array<string, mixed>  $data  record() + nouvelles_lignes, caisse_id, mode_paiement, ecart ('especes'|'avoir')
     */
    public function exchange(Sale $sale, array $data, ?int $userId): SaleReturn
    {
        return DB::transaction(function () use ($sale, $data, $userId) {
            $return = $this->record($sale, [...$data, 'decision' => 'echange', 'mode_remboursement' => null], $userId);
            $store = Store::query()->findOrFail($sale->boutique_id);
            $method = $data['mode_paiement'] ?? 'especes';

            $newSale = app(SaleService::class)->checkout($store, [
                'caisse_id' => $data['caisse_id'] ?? $sale->caisse_id,
                'client_id' => $sale->client_id,
                'mode_paiement' => $method,
                'avoir_echange' => (string) $return->montant,
                'cle_idempotence' => "echange-{$return->id}",
                'lignes' => collect($data['nouvelles_lignes'])->map(fn (array $l) => [
                    'produit_id' => $l['produit_id'], 'mode_prix' => $l['mode_prix'] ?? 'detail', 'quantite' => (string) $l['quantite'],
                ])->all(),
            ], $userId);

            $gap = bcsub((string) $return->montant, (string) $newSale->montant_total, 2);
            if (bccomp($gap, '0', 2) > 0) {
                if (($data['ecart'] ?? 'especes') === 'avoir' && $sale->client_id !== null) {
                    $this->accounts->recordReturnCredit(Customer::withTrashed()->findOrFail($sale->client_id), $gap, $userId, $return, "Écart de l’échange {$return->numero}");
                } else {
                    $register = CashRegister::query()->findOrFail($data['caisse_id'] ?? $sale->caisse_id);
                    if ($register->session_ouverte_id === null) {
                        throw new ReturnException("La caisse « {$register->nom} » n’a pas de session ouverte pour rendre l’écart.", 'AUCUNE_SESSION_CAISSE_OUVERTE');
                    }
                    $session = CashRegisterSession::query()->findOrFail($register->session_ouverte_id);
                    $this->cashRegisters->recordRefund($session, $gap, $userId, $return, "Écart rendu de l’échange {$return->numero}");
                    $return->session_caisse_id = $session->id;
                }
                $return->montant_rendu = $gap;
            }
            $return->vente_echange_id = $newSale->id;
            $return->save();

            return $return;
        });
    }

    /**
     * Décision sur un retour en quarantaine : `reintegrer` (le produit rentre
     * en stock vendable) ou `detruire` (entrée puis perte valorisée, origine
     * « avarie »). Une seule décision, tracée (qui, quand, note).
     */
    public function verify(SaleReturn $return, string $decision, ?string $note, ?int $userId): SaleReturn
    {
        return DB::transaction(function () use ($return, $decision, $note, $userId) {
            $return = SaleReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($return->statut_verification !== 'a_verifier') {
                throw new ReturnException('Ce retour n’attend pas de vérification.', 'RETOUR_DEJA_VERIFIE');
            }
            $store = Store::query()->findOrFail($return->boutique_id);
            $packaging = $this->features->allows($store, 'conditionnement');
            $reason = "Vérification du retour {$return->numero} : ".($decision === 'reintegrer' ? 'réintégré' : 'détruit').($note ? " ({$note})" : '');
            foreach ($return->lines()->get() as $line) {
                $item = SaleItem::query()->with('product')->findOrFail($line->ligne_vente_id);
                $product = $item->product;
                if ($product === null || ! $this->inventory->isInitialized($product)) {
                    continue;
                }
                $quantity = $packaging ? $product->stockQuantityFor($item->mode_prix, (string) $line->quantite) : (string) $line->quantite;
                $this->inventory->addStock($product, StockMovementType::ReturnIn, $quantity, $userId, $reason, $return);
                if ($decision === 'detruire') {
                    $this->inventory->removeStock($product, StockMovementType::Loss, $quantity, $userId, $reason, $return, LossOrigin::Spoiled);
                }
            }
            $return->update(['statut_verification' => $decision === 'reintegrer' ? 'reintegre' : 'detruit', 'verifie_par_id' => $userId, 'verifie_le' => now(), 'note_verification' => $note]);

            return $return;
        });
    }
}
