<?php

namespace App\Modules\Equipment\Services;

use App\Modules\Equipment\Enums\EquipmentStatus;
use App\Modules\Equipment\Enums\InterventionType;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Equipment\Models\EquipmentIntervention;
use App\Modules\Expenses\Enums\ExpenseCategory;
use App\Modules\Expenses\Services\ExpenseService;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Interventions sur un équipement — docs/modules.md §Equipment. Une panne le
 * met « en panne », une réparation le remet « en service », un entretien
 * repousse la prochaine échéance. Un coût payé peut devenir une dépense
 * (module Expenses), jamais saisie deux fois.
 */
class EquipmentService
{
    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly FeatureGate $features,
    ) {}

    /** Première échéance d'entretien à la création, si une fréquence est donnée. */
    public function create(array $data): Equipment
    {
        $data['statut'] ??= EquipmentStatus::InService->value;
        $data['actif'] ??= true;
        if (empty($data['prochain_entretien_le']) && ! empty($data['frequence_entretien_jours'])) {
            $data['prochain_entretien_le'] = now()->addDays((int) $data['frequence_entretien_jours'])->toDateString();
        }

        return Equipment::create($data);
    }

    /**
     * @param  array{type: string, survenue_le?: string|null, description: string, cout?: string|float|int|null, prestataire?: string|null, paiement?: array{mode: string, caisse_id?: int|null}|null}  $data
     */
    public function recordIntervention(Store $store, Equipment $equipment, array $data, ?int $userId): EquipmentIntervention
    {
        return DB::transaction(function () use ($store, $equipment, $data, $userId) {
            $equipment = Equipment::query()->whereKey($equipment->id)->lockForUpdate()->firstOrFail();
            $type = InterventionType::from($data['type']);
            $at = isset($data['survenue_le']) ? Carbon::parse($data['survenue_le']) : now();

            $expenseId = null;
            $cost = $data['cout'] ?? null;
            if ($cost !== null && (float) $cost > 0 && ! empty($data['paiement']) && $this->features->allows($store, 'depenses')) {
                $expenseId = $this->expenses->record([
                    'categorie' => ExpenseCategory::Maintenance->value,
                    'libelle' => match ($type) {
                        InterventionType::Maintenance => "Entretien : {$equipment->nom}",
                        InterventionType::Repair => "Réparation : {$equipment->nom}",
                        InterventionType::Breakdown => "Dépannage : {$equipment->nom}",
                    },
                    'montant' => $cost,
                    'mode' => $data['paiement']['mode'],
                    'caisse_id' => $data['paiement']['caisse_id'] ?? null,
                    'depense_le' => $at->isFuture() ? now() : $at,
                    'note' => $data['prestataire'] ?? null,
                ], $userId)->id;
            }

            $intervention = $equipment->interventions()->create([
                'type' => $type,
                'survenue_le' => $at,
                'description' => $data['description'],
                'cout' => $cost,
                'prestataire' => $data['prestataire'] ?? null,
                'depense_id' => $expenseId,
                'cree_par_id' => $userId,
            ]);

            match ($type) {
                InterventionType::Breakdown => $equipment->update(['statut' => EquipmentStatus::Broken]),
                InterventionType::Repair => $equipment->update(['statut' => EquipmentStatus::InService]),
                InterventionType::Maintenance => $equipment->update([
                    'dernier_entretien_le' => $at->toDateString(),
                    'prochain_entretien_le' => $equipment->frequence_entretien_jours ? $at->copy()->addDays($equipment->frequence_entretien_jours)->toDateString() : null,
                ]),
            };

            return $intervention;
        });
    }
}
