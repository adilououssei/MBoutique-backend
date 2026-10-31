<?php

namespace App\Modules\Orders\Services;

use App\Modules\Features\Services\FeatureGate;
use App\Modules\Orders\Enums\ReservationStatus;
use App\Modules\Orders\Models\Reservation;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Réservations internes. Une table ne porte qu'une réservation confirmée par
 * créneau (± 2 h, TABLE_DEJA_RESERVEE) ; elle apparaît « réservée » à partir
 * d'1 h avant l'heure prévue et jusqu'à 30 min après.
 */
class ReservationService
{
    private const SLOT_MINUTES = 120;

    public function __construct(private readonly FeatureGate $features) {}

    /** @param  array<string, mixed>  $data */
    public function save(?Reservation $reservation, array $data, ?int $userId): Reservation
    {
        return DB::transaction(function () use ($reservation, $data, $userId) {
            $reservation ??= new Reservation(['cree_par_id' => $userId, 'statut' => ReservationStatus::Confirmed]);
            $reservation->fill($data);
            if ($reservation->table_id !== null && $reservation->statut === ReservationStatus::Confirmed) {
                $clash = Reservation::query()
                    ->where('table_id', $reservation->table_id)
                    ->where('statut', ReservationStatus::Confirmed)
                    ->when($reservation->exists, fn ($q) => $q->whereKeyNot($reservation->id))
                    ->whereBetween('prevue_le', [$reservation->prevue_le->copy()->subMinutes(self::SLOT_MINUTES), $reservation->prevue_le->copy()->addMinutes(self::SLOT_MINUTES)])
                    ->lockForUpdate()
                    ->first();
                if ($clash !== null) {
                    throw new RuntimeException("Cette table est déjà réservée à {$clash->prevue_le->format('H:i')} ({$clash->nom_client}).");
                }
            }
            $reservation->save();

            return $reservation;
        });
    }

    /**
     * Réservation confirmée imminente par table (pour le plan de salle).
     *
     * @param  array<int, int>  $tableIds
     * @return array<int, array{id: int, nom_client: string, prevue_le: string, nombre_personnes: int}>
     */
    public function upcomingByTable(Store $store, array $tableIds): array
    {
        if ($tableIds === [] || ! $this->features->allows($store, 'reservations')) {
            return [];
        }

        return Reservation::query()
            ->whereIn('table_id', $tableIds)
            ->where('statut', ReservationStatus::Confirmed)
            ->whereBetween('prevue_le', [now()->subMinutes(30), now()->addHour()])
            ->orderBy('prevue_le')
            ->get()
            ->unique('table_id')
            ->mapWithKeys(fn (Reservation $r) => [$r->table_id => [
                'id' => $r->id, 'nom_client' => $r->nom_client, 'prevue_le' => $r->prevue_le->toIso8601String(), 'nombre_personnes' => $r->nombre_personnes,
            ]])
            ->all();
    }
}
