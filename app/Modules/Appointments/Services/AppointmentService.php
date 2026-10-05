<?php

namespace App\Modules\Appointments\Services;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Events\AppointmentBooked;
use App\Modules\Appointments\Exceptions\InvalidAppointmentTransitionException;
use App\Modules\Appointments\Exceptions\SlotUnavailableException;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Catalog\Models\Service;
use App\Modules\Employees\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'écriture des rendez-vous — docs/modules.md §Appointments.
 * Le non-chevauchement par employé est vérifié dans une transaction qui
 * verrouille la ligne de l'employé : deux réservations simultanées sur le
 * même créneau sont sérialisées, la seconde voit la première.
 */
class AppointmentService
{
    /** Durée par défaut d'un service sans durée renseignée. */
    public const DEFAULT_DURATION_MINUTES = 30;

    /**
     * @param  array{service_id: int, employe_id?: int|null, client_id?: int|null, nom_client?: string|null, telephone_client?: string|null, debut_le: string, duree_minutes?: int|null, notes?: string|null}  $data
     */
    public function book(array $data, ?int $userId): Appointment
    {
        return DB::transaction(function () use ($data, $userId) {
            [$start, $end] = $this->slot($data);
            $this->assertSlotFree($data['employe_id'] ?? null, $start, $end);

            $appointment = Appointment::create([
                'service_id' => $data['service_id'],
                'employe_id' => $data['employe_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'nom_client' => $data['nom_client'] ?? null,
                'telephone_client' => $data['telephone_client'] ?? null,
                'debut_le' => $start,
                'fin_le' => $end,
                'statut' => AppointmentStatus::Planned,
                'notes' => $data['notes'] ?? null,
                'cree_par_id' => $userId,
            ]);
            AppointmentBooked::dispatch($appointment, $userId);

            return $appointment;
        });
    }

    /** Déplacer ou modifier un rendez-vous encore actif (prévu ou confirmé). */
    public function reschedule(Appointment $appointment, array $data): Appointment
    {
        return DB::transaction(function () use ($appointment, $data) {
            $appointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $this->assertActive($appointment);

            $serviceChanged = isset($data['service_id']) && (int) $data['service_id'] !== $appointment->service_id;
            $merged = [
                'service_id' => $data['service_id'] ?? $appointment->service_id,
                'employe_id' => array_key_exists('employe_id', $data) ? $data['employe_id'] : $appointment->employe_id,
                'debut_le' => $data['debut_le'] ?? $appointment->debut_le->toIso8601String(),
                // Déplacer garde la durée ; changer de service reprend celle du service.
                'duree_minutes' => $data['duree_minutes']
                    ?? ($serviceChanged ? null : (int) $appointment->debut_le->diffInMinutes($appointment->fin_le)),
            ];
            [$start, $end] = $this->slot($merged);
            $this->assertSlotFree($merged['employe_id'], $start, $end, $appointment->id);

            $appointment->update([
                ...array_intersect_key($data, array_flip(['client_id', 'nom_client', 'telephone_client', 'notes'])),
                'service_id' => $merged['service_id'],
                'employe_id' => $merged['employe_id'],
                'debut_le' => $start,
                'fin_le' => $end,
            ]);

            return $appointment;
        });
    }

    /** prevu/confirme → confirme, termine ou absent. Un rendez-vous terminé peut référencer la vente encaissée. */
    public function changeStatus(Appointment $appointment, AppointmentStatus $status, ?int $saleId = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $status, $saleId) {
            $appointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $this->assertActive($appointment);

            $appointment->update([
                'statut' => $status,
                'vente_id' => $status === AppointmentStatus::Done ? ($saleId ?? $appointment->vente_id) : $appointment->vente_id,
            ]);

            return $appointment;
        });
    }

    public function cancel(Appointment $appointment, ?string $reason): Appointment
    {
        return DB::transaction(function () use ($appointment, $reason) {
            $appointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $this->assertActive($appointment);
            $appointment->update(['statut' => AppointmentStatus::Cancelled, 'motif_annulation' => $reason]);

            return $appointment;
        });
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function slot(array $data): array
    {
        $start = CarbonImmutable::parse($data['debut_le'])->utc()->startOfMinute();
        $minutes = $data['duree_minutes']
            ?? Service::withTrashed()->whereKey($data['service_id'])->value('duree_minutes')
            ?? self::DEFAULT_DURATION_MINUTES;

        return [$start, $start->addMinutes((int) $minutes)];
    }

    private function assertSlotFree(?int $employeeId, CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreId = null): void
    {
        if ($employeeId === null) {
            return; // « n'importe qui » : pas de planning individuel à protéger
        }

        // Verrou sur l'employé : sérialise les réservations concurrentes pour lui.
        Employee::query()->whereKey($employeeId)->lockForUpdate()->first();

        $conflict = Appointment::query()
            ->where('employe_id', $employeeId)
            ->whereIn('statut', AppointmentStatus::blocking())
            ->where('debut_le', '<', $end)
            ->where('fin_le', '>', $start)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->with('employee')
            ->first();

        if ($conflict !== null) {
            // Pas d'heure dans le message : elle serait en UTC, l'application
            // affiche elle-même les créneaux occupés à l'heure locale.
            throw new SlotUnavailableException("{$conflict->employee->nom} a déjà un rendez-vous sur ce créneau. Choisissez une autre heure ou un autre employé.");
        }
    }

    private function assertActive(Appointment $appointment): void
    {
        if (! $appointment->statut->isActive()) {
            throw new InvalidAppointmentTransitionException('Ce rendez-vous est déjà terminé, annulé ou marqué absent : il ne peut plus être modifié.');
        }
    }
}
