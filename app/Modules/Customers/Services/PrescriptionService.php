<?php

namespace App\Modules\Customers\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Exceptions\PrescriptionException;
use App\Modules\Customers\Models\Prescription;
use App\Modules\Customers\Models\PrescriptionDelivery;
use App\Modules\Customers\Models\PrescriptionLine;
use Illuminate\Support\Facades\DB;

/**
 * Ordonnances : enregistrement et délivrances. Outil de traçabilité :
 * aucune substitution (un produit délivré doit être celui prescrit), aucune
 * suggestion thérapeutique ; la décision reste au pharmacien.
 */
class PrescriptionService
{
    /** @param  array<string, mixed>  $data */
    public function save(?Prescription $prescription, array $data, ?int $userId): Prescription
    {
        return DB::transaction(function () use ($prescription, $data, $userId) {
            if ($prescription !== null && in_array($prescription->statut, ['delivree', 'annulee'], true)) {
                throw new PrescriptionException('Cette ordonnance est close.', 'ORDONNANCE_CLOSE');
            }
            $lines = $data['lignes'] ?? null;
            unset($data['lignes']);
            $prescription ??= new Prescription(['cree_par_id' => $userId]);
            $prescription->fill($data)->save();
            if ($prescription->numero === null) {
                $prescription->update(['numero' => sprintf('ORD-%s-%05d', now()->format('Ymd'), $prescription->id)]);
            }
            if ($lines !== null) {
                ksort($lines);
                $delivered = $prescription->lines()->get()->keyBy('id');
                foreach ($lines as $line) {
                    $existing = isset($line['id']) ? $delivered->pull((int) $line['id']) : null;
                    $attributes = [
                        'produit_id' => $line['produit_id'] ?? null,
                        'libelle' => $line['libelle'] ?? Product::query()->whereKey($line['produit_id'] ?? 0)->value('nom') ?? '—',
                        'posologie' => $line['posologie'] ?? null,
                        'quantite_prescrite' => (string) $line['quantite_prescrite'],
                    ];
                    $existing ? $existing->update($attributes) : $prescription->lines()->create($attributes);
                }
                // Une ligne déjà (partiellement) délivrée n'est jamais supprimée : traçabilité.
                foreach ($delivered as $orphan) {
                    if (bccomp((string) $orphan->quantite_delivree, '0', 3) === 0) {
                        $orphan->delete();
                    }
                }
            }
            $this->refreshStatus($prescription);

            return $prescription;
        });
    }

    /**
     * Vérifie puis enregistre la délivrance d'une ligne de vente. Produit
     * « sur ordonnance » sans ordonnance : refusé ; produit absent de
     * l'ordonnance ou quantité au-delà du prescrit restant : refusé.
     */
    public function deliver(?Prescription $prescription, Product $product, string $quantity, int $saleId, int $saleItemId, ?int $userId): void
    {
        if ($prescription === null) {
            if ($product->sur_ordonnance) {
                throw new PrescriptionException("« {$product->nom} » se délivre sur ordonnance : rattachez l’ordonnance à la vente.", 'ORDONNANCE_REQUISE');
            }

            return;
        }
        $line = PrescriptionLine::query()->where('ordonnance_id', $prescription->id)->where('produit_id', $product->id)->lockForUpdate()->first();
        if ($line === null) {
            if ($product->sur_ordonnance) {
                throw new PrescriptionException("« {$product->nom} » ne figure pas sur l’ordonnance {$prescription->numero}.", 'ORDONNANCE_INSUFFISANTE');
            }

            return; // Produit libre vendu avec l'ordonnance : pas une délivrance.
        }
        if (bccomp($quantity, $line->remaining(), 3) > 0) {
            throw new PrescriptionException("« {$product->nom} » : il reste ".rtrim(rtrim($line->remaining(), '0'), '.')." à délivrer sur l’ordonnance {$prescription->numero}.", 'ORDONNANCE_INSUFFISANTE');
        }
        $line->update(['quantite_delivree' => bcadd((string) $line->quantite_delivree, $quantity, 3)]);
        PrescriptionDelivery::create(['ordonnance_id' => $prescription->id, 'ligne_ordonnance_id' => $line->id, 'vente_id' => $saleId, 'ligne_vente_id' => $saleItemId, 'quantite' => $quantity, 'cree_par_id' => $userId]);
        $this->refreshStatus($prescription);
    }

    /** Vente annulée : ses délivrances le sont aussi, les quantités redeviennent à délivrer. */
    public function reverseForSale(int $saleId): void
    {
        $deliveries = PrescriptionDelivery::query()->where('vente_id', $saleId)->where('annulee', false)->get();
        foreach ($deliveries as $delivery) {
            $line = PrescriptionLine::query()->whereKey($delivery->ligne_ordonnance_id)->lockForUpdate()->first();
            $line?->update(['quantite_delivree' => bcsub((string) $line->quantite_delivree, (string) $delivery->quantite, 3)]);
            $delivery->update(['annulee' => true]);
            $this->refreshStatus(Prescription::query()->findOrFail($delivery->ordonnance_id));
        }
    }

    public function refreshStatus(Prescription $prescription): void
    {
        if ($prescription->statut === 'annulee') {
            return;
        }
        $lines = $prescription->lines()->get();
        $delivered = $lines->sum(fn (PrescriptionLine $l) => (float) $l->quantite_delivree);
        $status = $delivered <= 0 ? 'en_attente' : ($lines->every(fn (PrescriptionLine $l) => bccomp($l->remaining(), '0', 3) === 0) ? 'delivree' : 'partielle');
        $prescription->update(['statut' => $status]);
    }
}
