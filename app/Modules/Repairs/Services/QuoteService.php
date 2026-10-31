<?php

namespace App\Modules\Repairs\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Repairs\Exceptions\RepairException;
use App\Modules\Repairs\Models\Quote;
use App\Modules\Repairs\Models\QuoteLine;
use App\Modules\Repairs\Models\Repair;
use App\Modules\Sales\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Devis d'atelier : seul point d'écriture. Total = Σ lignes − remise. Un
 * devis accepté devient un dossier de travaux (RepairService) sans ressaisie :
 * client, objet, description, et toutes les lignes facturées.
 */
class QuoteService
{
    public function __construct(private readonly RepairService $repairs) {}

    /** @param  array<string, mixed>  $data */
    public function save(?Quote $quote, array $data, ?int $userId): Quote
    {
        return DB::transaction(function () use ($quote, $data, $userId) {
            if ($quote !== null && ! in_array($quote->effectiveStatus(), ['brouillon', 'envoye', 'expire'], true)) {
                throw new RepairException('Ce devis est déjà accepté, refusé ou transformé : il ne se modifie plus.', 'DEVIS_FIGE');
            }
            if (empty($data['client_id']) && filled($data['nouveau_client']['nom'] ?? null)) {
                $phone = filled($data['nouveau_client']['telephone'] ?? null) ? trim($data['nouveau_client']['telephone']) : null;
                $data['client_id'] = (($phone !== null ? Customer::query()->where('telephone', $phone)->first() : null)
                    ?? Customer::create(['nom' => trim($data['nouveau_client']['nom']), 'telephone' => $phone]))->id;
            }
            $lines = $data['lignes'] ?? null;
            unset($data['lignes'], $data['nouveau_client']);

            $quote ??= new Quote(['statut' => 'brouillon', 'cree_par_id' => $userId]);
            $quote->fill($data)->save();
            if ($quote->numero === null) {
                $quote->update(['numero' => sprintf('DEV-%s-%05d', now()->format('Ymd'), $quote->id)]);
            }
            if ($lines !== null) {
                // validate() peut réordonner les clés des lignes : on garde l'ordre saisi.
                ksort($lines);
                $quote->lines()->delete();
                foreach ($lines as $line) {
                    $this->createLine($quote, $line);
                }
            }
            $subtotal = $quote->lines()->get()->reduce(fn (string $c, QuoteLine $l) => bcadd($c, (string) $l->montant, 2), '0.00');
            $discount = Money::round((string) ($quote->montant_remise ?? '0'));
            if (bccomp($discount, $subtotal, 2) > 0) {
                throw new RepairException('La remise ne peut pas dépasser le total du devis.', 'REMISE_INVALIDE');
            }
            $quote->update(['montant_total' => bcsub($subtotal, $discount, 2)]);

            return $quote;
        });
    }

    /** envoye, accepte, refuse — un devis expiré ne peut plus être accepté. */
    public function changeStatus(Quote $quote, string $status): Quote
    {
        if (in_array($quote->statut, ['transforme'], true)) {
            throw new RepairException('Ce devis est déjà devenu un dossier.', 'DEVIS_FIGE');
        }
        if ($status === 'accepte' && $quote->effectiveStatus() === 'expire') {
            throw new RepairException('Ce devis a expiré : prolongez sa validité avant de l’accepter.', 'DEVIS_EXPIRE');
        }
        $quote->update(['statut' => $status]);

        return $quote;
    }

    /**
     * Devis accepté (ou en attente) → dossier de travaux : mêmes client et
     * lignes facturées ; la remise devient une ligne négative.
     *
     * @param  array{appareil?: string|null, prevu_le?: string|null, technicien_id?: int|null, techniciens?: array<int, int>|null, etat_reception?: string|null}  $data
     */
    public function convert(Quote $quote, array $data, ?int $userId): Repair
    {
        return DB::transaction(function () use ($quote, $data, $userId) {
            $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if ($quote->statut === 'transforme' || $quote->statut === 'refuse') {
                throw new RepairException('Ce devis est refusé ou déjà transformé en dossier.', 'DEVIS_FIGE');
            }
            if ($quote->effectiveStatus() === 'expire') {
                throw new RepairException('Ce devis a expiré : prolongez sa validité avant de le transformer.', 'DEVIS_EXPIRE');
            }
            $lines = $quote->lines()->get()->map(fn (QuoteLine $l) => [
                'type' => $l->type, 'service_id' => $l->service_id, 'libelle' => $l->libelle, 'quantite' => (string) $l->quantite, 'prix_unitaire' => (string) $l->prix_unitaire,
            ])->all();
            if (bccomp((string) $quote->montant_remise, '0', 2) > 0) {
                $lines[] = ['type' => 'remise', 'libelle' => 'Remise du devis', 'quantite' => '1', 'prix_unitaire' => bcmul((string) $quote->montant_remise, '-1', 2)];
            }

            $repair = $this->repairs->open([
                'client_id' => $quote->client_id,
                'nom_client' => $quote->client_id ? null : $quote->nom_client,
                'telephone_client' => $quote->client_id ? null : $quote->telephone_client,
                'appareil' => $data['appareil'] ?? $quote->objet,
                'panne' => $quote->description ?: $quote->objet,
                'etat_reception' => $data['etat_reception'] ?? null,
                'cout_estime' => $quote->montant_total,
                'devis_id' => $quote->id,
                'prevu_le' => $data['prevu_le'] ?? null,
                'technicien_id' => $data['technicien_id'] ?? null,
                'techniciens' => $data['techniciens'] ?? null,
                'prestations' => $lines,
            ], $userId);
            $quote->update(['statut' => 'transforme', 'reparation_id' => $repair->id]);

            return $repair;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function createLine(Quote $quote, array $data): void
    {
        $service = filled($data['service_id'] ?? null) ? Service::query()->findOrFail($data['service_id']) : null;
        $product = filled($data['produit_id'] ?? null) ? Product::query()->findOrFail($data['produit_id']) : null;
        $label = $data['libelle'] ?? $service?->nom ?? $product?->nom;
        if (blank($label)) {
            throw new RepairException('Chaque ligne du devis doit avoir un libellé.', 'LIGNE_INVALIDE');
        }
        $quantity = (string) ($data['quantite'] ?? '1');
        $price = Money::round((string) ($data['prix_unitaire'] ?? $service?->prix ?? $product?->prix_detail ?? '0'));
        $quote->lines()->create([
            'type' => $data['type'] ?? ($service ? 'prestation' : ($product ? 'materiau' : 'main_oeuvre')),
            'service_id' => $service?->id,
            'produit_id' => $product?->id,
            'libelle' => $label,
            'quantite' => $quantity,
            'prix_unitaire' => $price,
            'montant' => Money::round(bcmul($price, $quantity, 6)),
        ]);
    }
}
