<?php

namespace App\Modules\Appointments\Http\Controllers;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Exceptions\InvalidAppointmentTransitionException;
use App\Modules\Appointments\Exceptions\SlotUnavailableException;
use App\Modules\Appointments\Http\Requests\AppointmentRequest;
use App\Modules\Appointments\Http\Requests\ChangeAppointmentStatusRequest;
use App\Modules\Appointments\Http\Resources\AppointmentResource;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Services\AppointmentService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Closure;
use Illuminate\Http\Request;

class AppointmentController extends ApiController
{
    private const RELATIONS = ['service', 'employee', 'customer'];

    public function __construct(private readonly AppointmentService $appointments) {}

    /**
     * Agenda : `du`/`au` sont des instants (ISO 8601) — l'application envoie
     * le début et la fin de la journée locale, ce qui évite toute ambiguïté
     * de fuseau horaire côté serveur.
     */
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Appointment::class, $store]);

        $request->validate([
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
            'employe_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'],
            'statut' => ['nullable', 'string'],
        ]);

        $appointments = Appointment::query()
            ->with(self::RELATIONS)
            ->when($request->filled('du'), fn ($q) => $q->where('fin_le', '>', $request->date('du')->utc()))
            ->when($request->filled('au'), fn ($q) => $q->where('debut_le', '<', $request->date('au')->utc()))
            ->when($request->filled('employe_id'), fn ($q) => $q->where('employe_id', $request->integer('employe_id')))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('statut'), fn ($q) => $q->whereIn('statut', explode(',', $request->string('statut'))))
            ->orderBy('debut_le')
            ->paginate(min((int) $request->integer('par_page', 50), 100));

        return $this->success(AppointmentResource::collection($appointments));
    }

    public function store(Store $store, AppointmentRequest $request)
    {
        $this->authorize('create', [Appointment::class, $store]);

        return $this->guard(fn () => $this->success(
            new AppointmentResource($this->appointments->book($request->validated(), $request->user()?->id)->load(self::RELATIONS)),
            'Rendez-vous enregistré.',
            [],
            201,
        ));
    }

    public function show(Store $store, Appointment $appointment)
    {
        $this->authorize('view', [$appointment, $store]);

        return $this->success(new AppointmentResource($appointment->load(self::RELATIONS)));
    }

    public function update(Store $store, Appointment $appointment, AppointmentRequest $request)
    {
        $this->authorize('update', [$appointment, $store]);

        return $this->guard(fn () => $this->success(
            new AppointmentResource($this->appointments->reschedule($appointment, $request->validated())->load(self::RELATIONS)),
            'Rendez-vous mis à jour.',
        ));
    }

    /** POST /rendez-vous/{appointment}/statut — confirmer, terminer (avec la vente éventuelle) ou marquer absent. */
    public function changeStatus(Store $store, Appointment $appointment, ChangeAppointmentStatusRequest $request)
    {
        $this->authorize('update', [$appointment, $store]);

        return $this->guard(fn () => $this->success(
            new AppointmentResource($this->appointments->changeStatus(
                $appointment,
                AppointmentStatus::from($request->validated('statut')),
                $request->validated('vente_id'),
            )->load(self::RELATIONS)),
            'Rendez-vous mis à jour.',
        ));
    }

    public function cancel(Store $store, Appointment $appointment, Request $request)
    {
        $this->authorize('cancel', [$appointment, $store]);
        $data = $request->validate(['motif' => ['nullable', 'string', 'max:500']]);

        return $this->guard(fn () => $this->success(
            new AppointmentResource($this->appointments->cancel($appointment, $data['motif'] ?? null)->load(self::RELATIONS)),
            'Rendez-vous annulé.',
        ));
    }

    /** Erreurs métier → 422 avec un code exploitable par l'application. */
    private function guard(Closure $operation)
    {
        try {
            return $operation();
        } catch (SlotUnavailableException $e) {
            return $this->error($e->getMessage(), [], 422, 'CRENEAU_INDISPONIBLE');
        } catch (InvalidAppointmentTransitionException $e) {
            return $this->error($e->getMessage(), [], 422, 'RENDEZ_VOUS_CLOS');
        }
    }
}
