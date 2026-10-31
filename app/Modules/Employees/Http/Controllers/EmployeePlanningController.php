<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeAbsence;
use App\Modules\Employees\Models\EmployeeSchedule;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Planning d'un employé (salon) : prestations qu'il sait faire, horaires de
 * la semaine avec pause, congés et absences, taux de commission. Rien de
 * renseigné = aucune contrainte à la prise de rendez-vous.
 */
class EmployeePlanningController extends ApiController
{
    private const TIME = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';

    public function show(Store $store, Employee $employee)
    {
        $this->authorize('view', [$employee, $store]);

        return $this->success($this->planning($employee));
    }

    /** PUT { services?: int[], horaires?: [{jour, debut, fin, pause_debut?, pause_fin?}], taux_commission?: number|null } */
    public function update(Store $store, Employee $employee, Request $request)
    {
        $this->authorize('manage', [$employee, $store]);
        $data = $request->validate([
            'services' => ['sometimes', 'array'],
            'services.*' => ['integer', TenantScopedRules::existsInCurrentStore('services')->whereNull('deleted_at')],
            'horaires' => ['sometimes', 'array', 'max:7'],
            'horaires.*.jour' => ['required', 'integer', 'between:1,7', 'distinct'],
            'horaires.*.debut' => ['required', self::TIME],
            'horaires.*.fin' => ['required', self::TIME],
            'horaires.*.pause_debut' => ['nullable', self::TIME, 'required_with:horaires.*.pause_fin'],
            'horaires.*.pause_fin' => ['nullable', self::TIME, 'required_with:horaires.*.pause_debut'],
            'taux_commission' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        foreach ($data['horaires'] ?? [] as $i => $day) {
            if ($day['fin'] <= $day['debut'] || (! empty($day['pause_debut']) && ($day['pause_fin'] <= $day['pause_debut'] || $day['pause_debut'] < $day['debut'] || $day['pause_fin'] > $day['fin']))) {
                return $this->error('Horaires incohérents : la fin doit suivre le début et la pause tenir dans la journée.', ["horaires.{$i}" => ['Horaires incohérents.']], 422, 'VALIDATION_ECHOUEE');
            }
        }

        DB::transaction(function () use ($employee, $data, $store) {
            if (array_key_exists('services', $data)) {
                $employee->services()->sync(collect($data['services'])->unique()->mapWithKeys(fn ($id) => [$id => ['boutique_id' => $store->id]])->all());
            }
            if (array_key_exists('horaires', $data)) {
                $employee->schedules()->delete();
                foreach ($data['horaires'] as $day) {
                    $employee->schedules()->create([
                        'jour' => $day['jour'],
                        'debut' => $day['debut'],
                        'fin' => $day['fin'],
                        'pause_debut' => $day['pause_debut'] ?? null,
                        'pause_fin' => $day['pause_fin'] ?? null,
                    ]);
                }
            }
            if (array_key_exists('taux_commission', $data)) {
                $employee->update(['taux_commission' => $data['taux_commission']]);
            }
        });

        return $this->success($this->planning($employee->refresh()), 'Planning enregistré.');
    }

    public function storeAbsence(Store $store, Employee $employee, Request $request)
    {
        $this->authorize('manage', [$employee, $store]);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(EmployeeAbsence::TYPES))],
            'du' => ['required', 'date_format:Y-m-d'],
            'au' => ['required', 'date_format:Y-m-d', 'after_or_equal:du'],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);
        $employee->absences()->create([...$data, 'cree_par_id' => $request->user()?->id]);

        return $this->success($this->planning($employee), 'Absence enregistrée.', [], 201);
    }

    public function destroyAbsence(Store $store, Employee $employee, EmployeeAbsence $absence)
    {
        $this->authorize('manage', [$employee, $store]);
        $absence->delete();

        return $this->success($this->planning($employee), 'Absence supprimée.');
    }

    /** @return array<string, mixed> */
    private function planning(Employee $employee): array
    {
        return [
            'employe_id' => $employee->id,
            'taux_commission' => $employee->taux_commission,
            'services' => $employee->services()->orderBy('nom')->get(['services.id', 'services.nom'])->map(fn ($s) => ['id' => $s->id, 'nom' => $s->nom])->values(),
            'horaires' => $employee->schedules()->orderBy('jour')->get()->map(fn (EmployeeSchedule $s) => [
                'jour' => $s->jour, 'debut' => $s->debut, 'fin' => $s->fin, 'pause_debut' => $s->pause_debut, 'pause_fin' => $s->pause_fin,
            ])->values(),
            // Absences en cours et à venir (les passées restent en base, pour l'historique).
            'absences' => $employee->absences()->whereDate('au', '>=', now()->subDays(30)->toDateString())->orderBy('du')->get()->map(fn (EmployeeAbsence $a) => [
                'id' => $a->id, 'type' => $a->type, 'libelle' => EmployeeAbsence::TYPES[$a->type] ?? $a->type, 'du' => $a->du->toDateString(), 'au' => $a->au->toDateString(), 'motif' => $a->motif,
            ])->values(),
        ];
    }
}
