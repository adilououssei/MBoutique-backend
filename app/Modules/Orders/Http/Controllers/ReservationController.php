<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Enums\ReservationStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Reservation;
use App\Modules\Orders\Services\ReservationService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Réservations de tables (feature `reservations`), outil interne du personnel.
 * Lecture : commandes.voir ; création, modification, statut : commandes.creer.
 */
class ReservationController extends ApiController
{
    public function __construct(private readonly ReservationService $reservations) {}

    /** `date` (AAAA-MM-JJ, défaut aujourd'hui), `statut`, `a_venir=1`. */
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Order::class, $store]);
        $timezone = in_array((string) $store->fuseau_horaire, timezone_identifiers_list(), true) ? (string) $store->fuseau_horaire : 'UTC';

        $query = Reservation::query()->with(['diningTable', 'createdBy']);
        if ($request->boolean('a_venir')) {
            $query->where('prevue_le', '>=', now()->subHour())->where('statut', ReservationStatus::Confirmed);
        } else {
            $day = now($timezone)->parse($request->input('date', now($timezone)->toDateString()), $timezone)->startOfDay();
            $query->whereBetween('prevue_le', [$day->copy()->utc(), $day->copy()->endOfDay()->utc()]);
        }
        $reservations = $query->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->orderBy('prevue_le')->limit(200)->get();

        return $this->success($reservations->map(fn (Reservation $r) => $this->present($r)));
    }

    public function store(Store $store, Request $request)
    {
        $this->authorize('create', [Order::class, $store]);

        return $this->persist(null, $this->validated($request, true), $request->user()?->id, 201);
    }

    public function update(Store $store, Reservation $reservation, Request $request)
    {
        $this->authorize('create', [Order::class, $store]);

        return $this->persist($reservation, $this->validated($request, false), $request->user()?->id, 200);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'nom_client' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'telephone_client' => ['nullable', 'string', 'max:30'],
            'client_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('clients')->whereNull('deleted_at')],
            'prevue_le' => [$creating ? 'required' : 'sometimes', 'date'],
            'nombre_personnes' => [$creating ? 'required' : 'sometimes', 'integer', 'min:1', 'max:500'],
            'table_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('tables_salle')->where('actif', true)],
            'statut' => ['sometimes', Rule::enum(ReservationStatus::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function persist(?Reservation $reservation, array $data, ?int $userId, int $status)
    {
        try {
            $saved = $this->reservations->save($reservation, $data, $userId);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), [], 422, 'TABLE_DEJA_RESERVEE');
        }

        return $this->success($this->present($saved->load(['diningTable', 'createdBy'])), $status === 201 ? 'Réservation enregistrée.' : 'Réservation mise à jour.', [], $status);
    }

    /** @return array<string, mixed> */
    private function present(Reservation $r): array
    {
        return [
            'id' => $r->id,
            'nom_client' => $r->nom_client,
            'telephone_client' => $r->telephone_client,
            'client_id' => $r->client_id,
            'prevue_le' => $r->prevue_le,
            'nombre_personnes' => $r->nombre_personnes,
            'table' => $r->diningTable ? ['id' => $r->diningTable->id, 'nom' => $r->diningTable->nom] : null,
            'statut' => $r->statut->value,
            'note' => $r->note,
            'cree_par' => $r->createdBy ? ['id' => $r->createdBy->id, 'nom' => $r->createdBy->nom] : null,
            'cree_le' => $r->created_at,
        ];
    }
}
