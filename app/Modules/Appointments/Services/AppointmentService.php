<?php

namespace App\Modules\Appointments\Services;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Events\AppointmentBooked;
use App\Modules\Appointments\Exceptions\InvalidAppointmentTransitionException;
use App\Modules\Appointments\Exceptions\SalonException;
use App\Modules\Appointments\Exceptions\SlotUnavailableException;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\CustomerPackage;
use App\Modules\CashRegister\Exceptions\CashRegisterSessionClosedException;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Employees\Models\Employee;
use App\Modules\Sales\Exceptions\InvalidDepositException;
use App\Modules\Sales\Exceptions\NoOpenCashRegisterSessionException;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Sales\Support\Money;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Contracts\TenantContextContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'écriture des rendez-vous — docs/modules.md §Appointments.
 * Le non-chevauchement par employé est vérifié dans une transaction qui
 * verrouille la ligne de l'employé : deux réservations simultanées sur le
 * même créneau sont sérialisées, la seconde voit la première.
 *
 * Salon : plusieurs prestations (durée totale = somme), horaires et
 * habilitations (PlanningService), acompte encaissé à la réservation,
 * forfait du client (séance décomptée à la fin), encaissement du rendez-vous.
 */
class AppointmentService
{
    /** Durée par défaut d'un service sans durée renseignée. */
    public const DEFAULT_DURATION_MINUTES = 30;

    public function __construct(
        private readonly PlanningService $planning,
        private readonly PackageService $packages,
        private readonly CashRegisterService $cashRegisters,
        private readonly SaleService $sales,
        private readonly SalonSaleService $salon,
    ) {}

    /**
     * @param  array{service_id?: int, services?: array<int, array{service_id: int, employe_id?: int|null}>, employe_id?: int|null, client_id?: int|null, nom_client?: string|null, telephone_client?: string|null, debut_le: string, duree_minutes?: int|null, notes?: string|null, statut?: string|null, acompte?: string|float|int|null, caisse_id?: int|null, forfait_client_id?: int|null}  $data
     */
    public function book(array $data, ?int $userId): Appointment
    {
        return DB::transaction(function () use ($data, $userId) {
            $items = $this->items($data);
            [$start, $end] = $this->slot($data, $items);
            $this->assertSlotFree($data['employe_id'] ?? null, $items, $start, $end);
            $packageId = $this->checkedPackage($data['forfait_client_id'] ?? null, $data['client_id'] ?? null, $items);

            $appointment = Appointment::create([
                'service_id' => $items[0]['service_id'],
                'employe_id' => $data['employe_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'nom_client' => $data['nom_client'] ?? null,
                'telephone_client' => $data['telephone_client'] ?? null,
                'debut_le' => $start,
                'fin_le' => $end,
                // en_attente : demande à confirmer ; en_cours : client sans rendez-vous, installé tout de suite.
                'statut' => AppointmentStatus::tryFrom($data['statut'] ?? '') ?? AppointmentStatus::Planned,
                'commence_le' => ($data['statut'] ?? null) === AppointmentStatus::InProgress->value ? now() : null,
                'notes' => $data['notes'] ?? null,
                'forfait_client_id' => $packageId,
                'cree_par_id' => $userId,
            ]);
            $this->saveItems($appointment, $items, $data['employe_id'] ?? null);

            $deposit = Money::round((string) ($data['acompte'] ?? '0'));
            if (bccomp($deposit, '0', 2) > 0) {
                $this->collectDeposit($appointment, $deposit, $data['caisse_id'] ?? null, $userId);
            }
            AppointmentBooked::dispatch($appointment, $userId);

            return $appointment;
        });
    }

    /** Déplacer ou modifier un rendez-vous encore actif. */
    public function reschedule(Appointment $appointment, array $data): Appointment
    {
        return DB::transaction(function () use ($appointment, $data) {
            $appointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $this->assertActive($appointment);

            $servicesChanged = isset($data['services']) || (isset($data['service_id']) && (int) $data['service_id'] !== $appointment->service_id);
            $employeeId = array_key_exists('employe_id', $data) ? $data['employe_id'] : $appointment->employe_id;
            $items = $servicesChanged
                ? $this->items($data)
                : $appointment->items()->get()->map(fn ($i) => ['service_id' => $i->service_id, 'employe_id' => $i->employe_id === $appointment->employe_id ? null : $i->employe_id, 'duree_minutes' => $i->duree_minutes, 'prix' => (string) $i->prix])->all();
            if ($items === []) {
                $items = $this->items(['service_id' => $appointment->service_id]);
            }
            $merged = [
                'debut_le' => $data['debut_le'] ?? $appointment->debut_le->toIso8601String(),
                // Déplacer garde la durée ; changer de prestations reprend leur durée.
                'duree_minutes' => $data['duree_minutes']
                    ?? ($servicesChanged ? null : (int) $appointment->debut_le->diffInMinutes($appointment->fin_le)),
            ];
            [$start, $end] = $this->slot($merged, $items);
            $this->assertSlotFree($employeeId, $items, $start, $end, $appointment->id);
            $clientId = array_key_exists('client_id', $data) ? $data['client_id'] : $appointment->client_id;
            $packageId = array_key_exists('forfait_client_id', $data)
                ? $this->checkedPackage($data['forfait_client_id'], $clientId, $items)
                : $appointment->forfait_client_id;

            $appointment->update([
                ...array_intersect_key($data, array_flip(['client_id', 'nom_client', 'telephone_client', 'notes'])),
                'service_id' => $items[0]['service_id'],
                'employe_id' => $employeeId,
                'debut_le' => $start,
                'fin_le' => $end,
                'forfait_client_id' => $packageId,
            ]);
            if ($servicesChanged || array_key_exists('employe_id', $data)) {
                $appointment->items()->delete();
                $this->saveItems($appointment, $items, $employeeId);
            }

            return $appointment;
        });
    }

    /**
     * Transitions : en attente/prévu → confirmé ; → en cours ; → terminé
     * (avec la vente éventuelle ; décompte la séance du forfait) ou absent.
     */
    public function changeStatus(Appointment $appointment, AppointmentStatus $status, ?int $saleId = null, ?int $userId = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $status, $saleId, $userId) {
            $appointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $this->assertActive($appointment);

            $appointment->update([
                'statut' => $status,
                'vente_id' => $status === AppointmentStatus::Done ? ($saleId ?? $appointment->vente_id) : $appointment->vente_id,
                'commence_le' => $status === AppointmentStatus::InProgress ? now() : $appointment->commence_le,
            ]);
            if ($status === AppointmentStatus::Done) {
                $this->packages->useSession($appointment, $userId);
            }

            return $appointment;
        });
    }

    /**
     * Annuler. Un acompte versé est remboursé depuis `caisse_id` si
     * `rembourser_acompte`, sinon il reste acquis au salon.
     *
     * @param  array{rembourser_acompte?: bool, caisse_id?: int|null}  $options
     */
    public function cancel(Appointment $appointment, ?string $reason, array $options = [], ?int $userId = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $reason, $options, $userId) {
            $appointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $this->assertActive($appointment);
            if (($options['rembourser_acompte'] ?? false) && bccomp((string) $appointment->acompte, '0', 2) > 0) {
                $session = $this->openSession($options['caisse_id'] ?? null, 'Choisissez la caisse qui rembourse l’acompte, ou conservez-le.');
                try {
                    $this->cashRegisters->recordRefund($session, (string) $appointment->acompte, $userId, $appointment, "Remboursement de l'acompte du rendez-vous #{$appointment->id}");
                } catch (CashRegisterSessionClosedException $e) {
                    throw new NoOpenCashRegisterSessionException($e->getMessage(), previous: $e);
                }
                $appointment->update(['acompte' => 0]);
            }
            $appointment->update(['statut' => AppointmentStatus::Cancelled, 'motif_annulation' => $reason]);

            return $appointment;
        });
    }

    /**
     * Encaisse le rendez-vous : une vente avec toutes ses prestations (et
     * les produits ajoutés), l'employé de chaque prestation (commissions),
     * l'acompte imputé ; les prestations couvertes par le forfait ne sont
     * pas facturées. Puis le rendez-vous passe « terminé ». Clé
     * d'idempotence fixe : un double appui ne crée jamais deux ventes.
     *
     * `consommations` (facultatif) : quantités réellement consommées par les
     * prestations, qui remplacent les quantités prévues.
     *
     * @param  array{caisse_id?: int|null, mode_paiement?: string|null, montant_remise?: string|float|int|null, points_utilises?: int|null, acompte?: string|float|int|null, produits?: array<int, array{produit_id: int, mode_prix: string, quantite: string|float|int}>, consommations?: array<int, array{produit_id: int, quantite: string|float|int}>|null}  $data
     */
    public function checkout(Store $store, Appointment $appointment, array $data, ?int $userId): Appointment
    {
        return DB::transaction(function () use ($store, $appointment, $data, $userId) {
            $appointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            if ($appointment->statut === AppointmentStatus::Done) {
                return $appointment; // déjà encaissé (double appui)
            }
            $this->assertActive($appointment);

            // Prestations couvertes par le forfait : présentes dans la vente (réalisées,
            // produits consommés) mais remisées à 0 — la séance est payée d'avance.
            $covered = $this->packages->coveredServices($appointment);
            $lines = [];
            foreach ($appointment->items()->with('service')->get() as $item) {
                $line = ['service_id' => $item->service_id, 'employe_id' => $item->employe_id ?? $appointment->employe_id, 'quantite' => '1'];
                if (in_array($item->service_id, $covered, true)) {
                    $line['remise'] = (string) $item->service->prix;
                }
                $lines[] = $line;
            }
            foreach ($data['produits'] ?? [] as $product) {
                $lines[] = ['produit_id' => $product['produit_id'], 'mode_prix' => $product['mode_prix'], 'quantite' => (string) $product['quantite']];
            }

            $saleId = null;
            if ($lines !== []) {
                $method = $data['mode_paiement'] ?? 'especes';
                $deposit = (string) $appointment->acompte;
                if ($method === 'credit' && $appointment->client_id === null) {
                    throw new SalonException('Une vente à crédit doit avoir un client enregistré.', 'CLIENT_REQUIS');
                }
                if ($method === 'credit' && bccomp($deposit, '0', 2) > 0) {
                    throw new InvalidDepositException('Ce rendez-vous a un acompte : encaissez le reste autrement qu’à crédit.');
                }
                if (empty($data['caisse_id'])) {
                    throw new SalonException('Choisissez la caisse qui encaisse.', 'CAISSE_REQUISE');
                }
                $sale = $this->sales->checkout($store, [
                    'caisse_id' => $data['caisse_id'],
                    'client_id' => $appointment->client_id,
                    'mode_paiement' => $method,
                    'acompte' => $method === 'credit' ? ($data['acompte'] ?? '0') : null,
                    'montant_remise' => $data['montant_remise'] ?? null,
                    'points_utilises' => $data['points_utilises'] ?? null,
                    'deja_encaisse' => $deposit,
                    'cle_idempotence' => "rendez-vous-{$appointment->id}",
                    'lignes' => $lines,
                    'consommations_reelles' => $data['consommations'] ?? null,
                ], $userId);
                $saleId = $sale->id;
            }

            return $this->changeStatus($appointment, AppointmentStatus::Done, $saleId, $userId);
        });
    }

    /**
     * Produits que les prestations du rendez-vous vont consommer (fiches des
     * prestations), pour les vérifier ou les corriger avant l'encaissement.
     *
     * @return array<int, array{produit_id: int, nom: string, unite: ?string, quantite: string}>
     */
    public function plannedConsumption(Store $store, Appointment $appointment): array
    {
        $needs = [];
        foreach ($appointment->items()->with('service')->get() as $item) {
            foreach ($this->salon->consumables($store, $item->service, '1') as $productId => $quantity) {
                $needs[$productId] = bcadd($needs[$productId] ?? '0', $quantity, 3);
            }
        }
        $products = Product::withTrashed()->whereIn('id', array_keys($needs))->get()->keyBy('id');

        return collect($needs)->map(fn (string $q, int $id) => ['produit_id' => $id, 'nom' => $products[$id]?->nom ?? '—', 'unite' => $products[$id]?->unite, 'quantite' => $q])->values()->all();
    }

    /**
     * Prestations demandées : `services` (salon) ou `service_id` seul.
     *
     * @return array<int, array{service_id: int, employe_id: ?int, duree_minutes: int, prix: string}>
     */
    private function items(array $data): array
    {
        $requested = ! empty($data['services']) ? $data['services'] : [['service_id' => $data['service_id']]];
        $services = Service::withTrashed()->whereIn('id', array_column($requested, 'service_id'))->get()->keyBy('id');

        return array_values(array_map(fn (array $line) => [
            'service_id' => (int) $line['service_id'],
            'employe_id' => isset($line['employe_id']) ? (int) $line['employe_id'] : null,
            'duree_minutes' => (int) ($services[$line['service_id']]?->effectiveDuration() ?? self::DEFAULT_DURATION_MINUTES),
            'prix' => Money::round((string) ($services[$line['service_id']]?->prix ?? '0')),
        ], $requested));
    }

    private function saveItems(Appointment $appointment, array $items, ?int $employeeId): void
    {
        foreach ($items as $i => $item) {
            $appointment->items()->create([
                'service_id' => $item['service_id'],
                'employe_id' => $item['employe_id'] ?? $employeeId,
                'duree_minutes' => $item['duree_minutes'],
                'prix' => $item['prix'],
                'ordre' => $i,
            ]);
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function slot(array $data, array $items): array
    {
        $start = CarbonImmutable::parse($data['debut_le'])->utc()->startOfMinute();
        $minutes = $data['duree_minutes'] ?? array_sum(array_column($items, 'duree_minutes'));

        return [$start, $start->addMinutes((int) ($minutes ?: self::DEFAULT_DURATION_MINUTES))];
    }

    private function assertSlotFree(?int $employeeId, array $items, CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreId = null): void
    {
        $store = Store::query()->findOrFail(app(TenantContextContract::class)->getStoreId());
        $serviceIds = array_column($items, 'service_id');

        // Employé du rendez-vous et ceux d'une prestation précise ; chacun est vérifié sur tout le créneau.
        $byEmployee = [];
        foreach ($items as $item) {
            $id = $item['employe_id'] ?? $employeeId;
            if ($id !== null) {
                $byEmployee[$id][] = $item['service_id'];
            }
        }
        if ($byEmployee === []) {
            $this->planning->assertBookable($store, null, $serviceIds, $start, $end); // « n'importe qui » : horaires du salon seulement

            return;
        }
        ksort($byEmployee);
        foreach ($byEmployee as $id => $ids) {
            // Verrou sur l'employé : sérialise les réservations concurrentes pour lui.
            Employee::query()->whereKey($id)->lockForUpdate()->first();
            $this->planning->assertBookable($store, $id, $ids, $start, $end, $ignoreId);
            $conflict = $this->planning->conflict($id, $start, $end, $ignoreId);
            if ($conflict !== null) {
                $name = Employee::withTrashed()->find($id)?->nom ?? 'L’employé';
                // Pas d'heure dans le message : elle serait en UTC, l'application
                // affiche elle-même les créneaux occupés à l'heure locale.
                throw new SlotUnavailableException("{$name} a déjà un rendez-vous sur ce créneau. Choisissez une autre heure ou un autre employé.");
            }
        }
    }

    private function checkedPackage(?int $customerPackageId, ?int $customerId, array $items): ?int
    {
        if ($customerPackageId === null) {
            return null;
        }
        $customerPackage = CustomerPackage::query()->findOrFail($customerPackageId);
        $this->packages->assertUsableFor($customerPackage, $customerId, array_column($items, 'service_id'));

        return $customerPackage->id;
    }

    private function collectDeposit(Appointment $appointment, string $amount, ?int $registerId, ?int $userId): void
    {
        $session = $this->openSession($registerId, 'Choisissez la caisse qui encaisse l’acompte.');
        try {
            $this->cashRegisters->recordReceipt($session, $amount, $userId, $appointment, "Acompte du rendez-vous #{$appointment->id}");
        } catch (CashRegisterSessionClosedException $e) {
            throw new NoOpenCashRegisterSessionException($e->getMessage(), previous: $e);
        }
        $appointment->update(['acompte' => $amount]);
    }

    private function openSession(?int $registerId, string $missing): CashRegisterSession
    {
        if ($registerId === null) {
            throw new SalonException($missing, 'CAISSE_REQUISE');
        }
        $register = CashRegister::query()->findOrFail($registerId);
        if ($register->session_ouverte_id === null) {
            throw new NoOpenCashRegisterSessionException("La caisse \"{$register->nom}\" n'a pas de session ouverte.");
        }

        return CashRegisterSession::query()->findOrFail($register->session_ouverte_id);
    }

    private function assertActive(Appointment $appointment): void
    {
        if (! $appointment->statut->isActive()) {
            throw new InvalidAppointmentTransitionException('Ce rendez-vous est déjà terminé, annulé ou marqué absent : il ne peut plus être modifié.');
        }
    }
}
