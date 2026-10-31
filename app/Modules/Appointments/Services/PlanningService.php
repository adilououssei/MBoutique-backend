<?php

namespace App\Modules\Appointments\Services;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Exceptions\SlotUnavailableException;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Support\SalonSettings;
use App\Modules\Catalog\Models\Service;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeAbsence;
use App\Modules\Employees\Models\EmployeeSchedule;
use App\Modules\Tenancy\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Disponibilités (salon) : horaires d'ouverture, horaires et pauses de
 * l'employé, congés et absences, habilitation aux prestations, puis
 * chevauchement. Chaque règle ne s'applique que si elle est configurée :
 * un salon ou une coiffure sans planning garde le comportement d'avant.
 */
class PlanningService
{
    /** Fenêtre par défaut quand ni le salon ni l'employé n'ont d'horaires. */
    private const DEFAULT_OPEN = '08:00';

    private const DEFAULT_CLOSE = '20:00';

    /** @param  array<int, int>  $serviceIds */
    public function assertBookable(Store $store, ?int $employeeId, array $serviceIds, CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreId = null): void
    {
        $timezone = SalonSettings::timezone($store);
        $localStart = $start->setTimezone($timezone);
        $localEnd = $end->setTimezone($timezone);

        $hours = SalonSettings::openingHours($store);
        if ($hours !== null) {
            $day = $hours[$localStart->dayOfWeekIso];
            if ($day === null) {
                throw new SlotUnavailableException('Le salon est fermé ce jour-là.', 'SALON_FERME');
            }
            if (! $this->within($localStart, $localEnd, $day['ouvre'], $day['ferme'])) {
                throw new SlotUnavailableException("En dehors des horaires d'ouverture ({$day['ouvre']} – {$day['ferme']}).", 'SALON_FERME');
            }
        }

        if ($employeeId === null) {
            return;
        }
        $employee = Employee::query()->findOrFail($employeeId);
        $this->assertQualified($employee, $serviceIds);
        if (($reason = $this->unavailability($employee, $localStart, $localEnd)) !== null) {
            throw new SlotUnavailableException($reason, 'EMPLOYE_INDISPONIBLE');
        }
    }

    /** @param  array<int, int>  $serviceIds */
    public function assertQualified(Employee $employee, array $serviceIds): void
    {
        $qualified = $employee->services()->pluck('services.id');
        if ($qualified->isEmpty()) {
            return; // aucune habilitation renseignée : toutes les prestations
        }
        // Une formule est faisable si l'employé est habilité à la formule ou à tous ses composants.
        $missing = array_filter(array_diff($serviceIds, $qualified->all()), function (int $id) use ($qualified) {
            $components = Service::withTrashed()->find($id)?->components()->pluck('services.id');

            return $components === null || $components->isEmpty() || $components->diff($qualified)->isNotEmpty();
        });
        if ($missing !== []) {
            $names = Service::withTrashed()->whereIn('id', $missing)->pluck('nom')->implode(', ');
            throw new SlotUnavailableException("{$employee->nom} n'est pas habilité(e) pour : {$names}.", 'EMPLOYE_NON_HABILITE');
        }
    }

    /**
     * Rendez-vous qui chevauchent le créneau pour cet employé (rendez-vous
     * principal ou prestation qu'il réalise dans un rendez-vous partagé).
     */
    public function conflict(int $employeeId, CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreId = null): ?Appointment
    {
        return Appointment::query()
            ->where(fn ($q) => $q->where('employe_id', $employeeId)->orWhereHas('items', fn ($i) => $i->where('employe_id', $employeeId)))
            ->whereIn('statut', AppointmentStatus::blocking())
            ->where('debut_le', '<', $end)
            ->where('fin_le', '>', $start)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->with('employee')
            ->first();
    }

    /**
     * Créneaux libres d'une journée locale pour une durée donnée, par employé
     * habilité (pas de 15 min). Les créneaux passés sont exclus.
     *
     * @param  array<int, int>  $serviceIds
     * @return array<int, array{employe_id: int, nom: string, creneaux: array<int, string>}>
     */
    public function freeSlots(Store $store, string $date, array $serviceIds, int $minutes, ?int $employeeId = null, int $step = 15): array
    {
        $timezone = SalonSettings::timezone($store);
        $day = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $hours = SalonSettings::openingHours($store);
        $opening = $hours === null ? ['ouvre' => self::DEFAULT_OPEN, 'ferme' => self::DEFAULT_CLOSE] : $hours[$day->dayOfWeekIso];
        if ($opening === null) {
            return [];
        }

        $employees = Employee::query()->where('actif', true)->when($employeeId, fn ($q) => $q->whereKey($employeeId))->orderBy('nom')->get();
        $now = CarbonImmutable::now();
        $result = [];
        foreach ($employees as $employee) {
            try {
                $this->assertQualified($employee, $serviceIds);
            } catch (SlotUnavailableException) {
                continue;
            }
            $schedule = $this->scheduleFor($employee, $day->dayOfWeekIso);
            if ($schedule === false) {
                continue; // jour de repos
            }
            $from = max($opening['ouvre'], $schedule['debut'] ?? $opening['ouvre']);
            $to = min($opening['ferme'], $schedule['fin'] ?? $opening['ferme']);

            $busy = Appointment::query()
                ->where(fn ($q) => $q->where('employe_id', $employee->id)->orWhereHas('items', fn ($i) => $i->where('employe_id', $employee->id)))
                ->whereIn('statut', AppointmentStatus::blocking())
                ->where('debut_le', '<', $day->endOfDay()->utc())
                ->where('fin_le', '>', $day->utc())
                ->get(['debut_le', 'fin_le']);
            $absent = EmployeeAbsence::query()->where('employe_id', $employee->id)->whereDate('du', '<=', $day->toDateString())->whereDate('au', '>=', $day->toDateString())->exists();
            if ($absent) {
                continue;
            }

            $slots = [];
            for ($cursor = $this->at($day, $from); $cursor->addMinutes($minutes)->lte($this->at($day, $to)); $cursor = $cursor->addMinutes($step)) {
                $end = $cursor->addMinutes($minutes);
                if ($cursor->lt($now)) {
                    continue;
                }
                if (! empty($schedule['pause_debut']) && $cursor->lt($this->at($day, $schedule['pause_fin'])) && $end->gt($this->at($day, $schedule['pause_debut']))) {
                    continue;
                }
                $taken = $busy->contains(fn ($a) => $a->debut_le->lt($end) && $a->fin_le->gt($cursor));
                if (! $taken) {
                    $slots[] = $cursor->format('H:i');
                }
            }
            $result[] = ['employe_id' => $employee->id, 'nom' => $employee->nom, 'creneaux' => $slots];
        }

        return $result;
    }

    /** Raison de l'indisponibilité de l'employé sur ce créneau local, ou null. */
    private function unavailability(Employee $employee, CarbonImmutable $start, CarbonImmutable $end): ?string
    {
        $absence = EmployeeAbsence::query()->where('employe_id', $employee->id)
            ->whereDate('du', '<=', $end->toDateString())->whereDate('au', '>=', $start->toDateString())->first();
        if ($absence !== null) {
            $label = mb_strtolower(EmployeeAbsence::TYPES[$absence->type] ?? 'absence');
            $until = $absence->au->format('d/m/Y');

            return "{$employee->nom} est en {$label} jusqu'au {$until}.";
        }

        $schedule = $this->scheduleFor($employee, $start->dayOfWeekIso);
        if ($schedule === false) {
            return "{$employee->nom} ne travaille pas ce jour-là.";
        }
        if ($schedule === null) {
            return null;
        }
        if (! $this->within($start, $end, $schedule['debut'], $schedule['fin'])) {
            return "En dehors des horaires de {$employee->nom} ({$schedule['debut']} – {$schedule['fin']}).";
        }
        if (! empty($schedule['pause_debut'])) {
            $day = $start->startOfDay();
            if ($start->lt($this->at($day, $schedule['pause_fin'])) && $end->gt($this->at($day, $schedule['pause_debut']))) {
                return "{$employee->nom} est en pause de {$schedule['pause_debut']} à {$schedule['pause_fin']}.";
            }
        }

        return null;
    }

    /**
     * Horaires du jour : tableau, null si l'employé n'a aucun horaire
     * (pas de contrainte), false si c'est un jour de repos.
     *
     * @return array{debut: string, fin: string, pause_debut: ?string, pause_fin: ?string}|false|null
     */
    private function scheduleFor(Employee $employee, int $dayOfWeek): array|false|null
    {
        /** @var Collection<int, EmployeeSchedule> $rows */
        $rows = EmployeeSchedule::query()->where('employe_id', $employee->id)->get()->keyBy('jour');
        if ($rows->isEmpty()) {
            return null;
        }
        $row = $rows->get($dayOfWeek);

        return $row === null ? false : ['debut' => $row->debut, 'fin' => $row->fin, 'pause_debut' => $row->pause_debut, 'pause_fin' => $row->pause_fin];
    }

    private function within(CarbonImmutable $start, CarbonImmutable $end, string $open, string $close): bool
    {
        $day = $start->startOfDay();

        return $start->gte($this->at($day, $open)) && $end->lte($this->at($day, $close)) && $start->isSameDay($end->subMinute());
    }

    private function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $day->setTime($h, $m);
    }
}
