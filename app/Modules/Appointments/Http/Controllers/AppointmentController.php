<?php

namespace App\Modules\Appointments\Http\Controllers;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Exceptions\InvalidAppointmentTransitionException;
use App\Modules\Appointments\Exceptions\SalonException;
use App\Modules\Appointments\Exceptions\SlotUnavailableException;
use App\Modules\Appointments\Http\Requests\AppointmentRequest;
use App\Modules\Appointments\Http\Requests\ChangeAppointmentStatusRequest;
use App\Modules\Appointments\Http\Resources\AppointmentResource;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Services\AppointmentService;
use App\Modules\Appointments\Services\PlanningService;
use App\Modules\Catalog\Enums\PricingMode;
use App\Modules\Catalog\Models\Service;
use App\Modules\Conservation\Exceptions\LotNotSellableException;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\StockNotInitializedException;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Exceptions\InvalidDepositException;
use App\Modules\Sales\Exceptions\InvalidDiscountException;
use App\Modules\Sales\Exceptions\NoOpenCashRegisterSessionException;
use App\Modules\Sales\Exceptions\PricingModeNotAvailableException;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use App\Shared\Validation\TenantScopedRules;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentController extends ApiController
{
    private const RELATIONS = ['service', 'employee', 'customer', 'items.service', 'items.employee', 'customerPackage.package'];

    public function __construct(
        private readonly AppointmentService $appointments,
        private readonly PlanningService $planning,
    ) {}

    /**
     * Agenda : `du`/`au` sont des instants (ISO 8601) — l'application envoie
     * le début et la fin de la journée locale, ce qui évite toute ambiguïté
     * de fuseau horaire côté serveur. Filtres par employé (rendez-vous ou
     * prestation qu'il réalise), prestation, client et statut.
     */
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Appointment::class, $store]);

        $request->validate([
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
            'employe_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'],
            'statut' => ['nullable', 'string'],
        ]);

        $appointments = Appointment::query()
            ->with(self::RELATIONS)
            ->when($request->filled('du'), fn ($q) => $q->where('fin_le', '>', $request->date('du')->utc()))
            ->when($request->filled('au'), fn ($q) => $q->where('debut_le', '<', $request->date('au')->utc()))
            ->when($request->filled('employe_id'), fn ($q) => $q->where(fn ($w) => $w->where('employe_id', $request->integer('employe_id'))->orWhereHas('items', fn ($i) => $i->where('employe_id', $request->integer('employe_id')))))
            ->when($request->filled('service_id'), fn ($q) => $q->where(fn ($w) => $w->where('service_id', $request->integer('service_id'))->orWhereHas('items', fn ($i) => $i->where('service_id', $request->integer('service_id')))))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('statut'), fn ($q) => $q->whereIn('statut', explode(',', $request->string('statut'))))
            ->orderBy('debut_le', $request->boolean('recents') ? 'desc' : 'asc')
            ->paginate(min((int) $request->integer('par_page', 50), 200));

        return $this->success(AppointmentResource::collection($appointments));
    }

    /**
     * GET rendez-vous/creneaux?date=AAAA-MM-JJ&services=1,2[&employe_id=] —
     * heures de début libres (heure locale) par employé habilité.
     */
    public function freeSlots(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Appointment::class, $store]);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'services' => ['required', 'string'],
            'employe_id' => ['nullable', 'integer'],
            'duree_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
        ]);
        $ids = array_values(array_filter(array_map('intval', explode(',', $data['services']))));
        $services = Service::query()->whereIn('id', $ids)->get();
        if ($services->isEmpty()) {
            return $this->error('Choisissez au moins une prestation.', [], 422, 'VALIDATION_ECHOUEE');
        }
        $minutes = (int) ($data['duree_minutes'] ?? $services->sum(fn (Service $s) => $s->effectiveDuration() ?: AppointmentService::DEFAULT_DURATION_MINUTES));

        return $this->success([
            'date' => $data['date'],
            'duree_minutes' => $minutes,
            'employes' => $this->planning->freeSlots($store, $data['date'], $services->pluck('id')->all(), $minutes, $data['employe_id'] ?? null),
        ]);
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

    /** POST /rendez-vous/{appointment}/statut — confirmer, commencer, terminer (avec la vente éventuelle) ou marquer absent. */
    public function changeStatus(Store $store, Appointment $appointment, ChangeAppointmentStatusRequest $request)
    {
        $this->authorize('update', [$appointment, $store]);

        return $this->guard(fn () => $this->success(
            new AppointmentResource($this->appointments->changeStatus(
                $appointment,
                AppointmentStatus::from($request->validated('statut')),
                $request->validated('vente_id'),
                $request->user()?->id,
            )->load(self::RELATIONS)),
            'Rendez-vous mis à jour.',
        ));
    }

    /** POST /rendez-vous/{appointment}/encaisser — la vente de toutes les prestations, puis « terminé ». */
    public function checkout(Store $store, Appointment $appointment, Request $request)
    {
        $this->authorize('update', [$appointment, $store]);
        $this->authorize('create', [Sale::class, $store]);
        $data = $request->validate([
            'caisse_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
            'mode_paiement' => ['nullable', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::acceptedForCheckout()))],
            'montant_remise' => ['nullable', 'numeric', 'min:0'],
            'acompte' => ['nullable', 'numeric', 'min:0'],
            'points_utilises' => ['nullable', 'integer', 'min:0'],
            'produits' => ['nullable', 'array'],
            'produits.*.produit_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('produits')->whereNull('deleted_at')],
            'produits.*.mode_prix' => ['required', Rule::enum(PricingMode::class)],
            'produits.*.quantite' => ['required', 'numeric', 'gt:0'],
            // Quantités réellement consommées (remplacent les prévues) : personnes habilitées au stock.
            'consommations' => ['nullable', 'array'],
            'consommations.*.produit_id' => ['required', 'integer', 'distinct', TenantScopedRules::existsInCurrentStore('produits')],
            'consommations.*.quantite' => ['required', 'numeric', 'min:0'],
        ]);
        if (isset($data['consommations']) && ! $request->user()->can('stock.ajuster')) {
            return $this->error('Seule une personne autorisée à ajuster le stock peut corriger les produits consommés.', [], 403, 'ACCES_INTERDIT');
        }
        if (($data['mode_paiement'] ?? null) === 'credit') {
            $this->authorize('sellOnCredit', [Sale::class, $store]);
        }

        return $this->guard(fn () => $this->success(
            new AppointmentResource($this->appointments->checkout($store, $appointment, $data, $request->user()?->id)->load(self::RELATIONS)),
            'Rendez-vous encaissé.',
        ));
    }

    /** GET /rendez-vous/{appointment}/consommations — produits prévus par les prestations. */
    public function consumption(Store $store, Appointment $appointment)
    {
        $this->authorize('view', [$appointment, $store]);

        return $this->success($this->appointments->plannedConsumption($store, $appointment));
    }

    public function cancel(Store $store, Appointment $appointment, Request $request)
    {
        $this->authorize('cancel', [$appointment, $store]);
        $data = $request->validate([
            'motif' => ['nullable', 'string', 'max:500'],
            'rembourser_acompte' => ['sometimes', 'boolean'],
            'caisse_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
        ]);

        return $this->guard(fn () => $this->success(
            new AppointmentResource($this->appointments->cancel($appointment, $data['motif'] ?? null, $data, $request->user()?->id)->load(self::RELATIONS)),
            'Rendez-vous annulé.',
        ));
    }

    /** Erreurs métier → 422 avec un code exploitable par l'application. */
    private function guard(Closure $operation)
    {
        try {
            return $operation();
        } catch (SlotUnavailableException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        } catch (InvalidAppointmentTransitionException $e) {
            return $this->error($e->getMessage(), [], 422, 'RENDEZ_VOUS_CLOS');
        } catch (SalonException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        } catch (NoOpenCashRegisterSessionException $e) {
            return $this->error($e->getMessage(), [], 422, 'AUCUNE_SESSION_CAISSE_OUVERTE');
        } catch (InvalidDepositException $e) {
            return $this->error($e->getMessage(), [], 422, 'ACOMPTE_INVALIDE');
        } catch (InvalidDiscountException $e) {
            return $this->error($e->getMessage(), [], 422, 'REMISE_INVALIDE');
        } catch (PricingModeNotAvailableException $e) {
            return $this->error($e->getMessage(), [], 422, 'MODE_PRIX_INDISPONIBLE');
        } catch (LotNotSellableException $e) {
            return $this->error($e->getMessage(), [], 422, 'LOT_NON_VENDABLE');
        } catch (InsufficientStockException $e) {
            return $this->error($e->getMessage(), [], 422, 'STOCK_INSUFFISANT');
        } catch (StockNotInitializedException $e) {
            return $this->error($e->getMessage().' Initialisez-le depuis la fiche du produit.', [], 422, 'STOCK_NON_INITIALISE');
        }
    }
}
