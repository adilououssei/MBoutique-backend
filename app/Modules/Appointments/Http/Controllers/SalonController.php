<?php

namespace App\Modules\Appointments\Http\Controllers;

use App\Modules\Appointments\Exceptions\SalonException;
use App\Modules\Appointments\Models\Commission;
use App\Modules\Appointments\Models\LoyaltyMovement;
use App\Modules\Appointments\Services\CommissionService;
use App\Modules\Appointments\Services\LoyaltyService;
use App\Modules\Appointments\Support\SalonSettings;
use App\Modules\Customers\Models\Customer;
use App\Modules\Employees\Exceptions\CashRegisterNotOpenException;
use App\Modules\Employees\Models\Employee;
use App\Modules\Sales\Support\Money;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Réglages du salon (horaires d'ouverture, barème de fidélité), relevé et
 * paiement des commissions, fidélité d'un client.
 */
class SalonController extends ApiController
{
    private const TIME = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';

    public function __construct(private readonly CommissionService $commissions, private readonly LoyaltyService $loyalty) {}

    public function settings(Store $store)
    {
        return $this->success($this->presentSettings($store));
    }

    /** PUT parametres/salon { horaires_ouverture?: {1..7: {ouvre, ferme}|null}|null, fidelite?: {montant_par_point, valeur_point}|null } */
    public function updateSettings(Store $store, Request $request)
    {
        abort_unless($request->user()->can('membres.gerer'), 403);
        $data = $request->validate([
            'horaires_ouverture' => ['sometimes', 'nullable', 'array'],
            'horaires_ouverture.*' => ['nullable', 'array'],
            'horaires_ouverture.*.ouvre' => ['required', self::TIME],
            'horaires_ouverture.*.ferme' => ['required', self::TIME],
            'fidelite' => ['sometimes', 'nullable', 'array'],
            'fidelite.montant_par_point' => ['required_with:fidelite', 'numeric', 'gt:0'],
            'fidelite.valeur_point' => ['required_with:fidelite', 'numeric', 'gt:0'],
        ]);

        $settings = $store->parametres?->getArrayCopy() ?? [];
        if (array_key_exists('horaires_ouverture', $data)) {
            $hours = null;
            if ($data['horaires_ouverture'] !== null) {
                $hours = [];
                for ($day = 1; $day <= 7; $day++) {
                    $slot = $data['horaires_ouverture'][$day] ?? null;
                    if ($slot !== null && $slot['ferme'] <= $slot['ouvre']) {
                        return $this->error('L’heure de fermeture doit suivre l’heure d’ouverture.', ["horaires_ouverture.{$day}" => ['Horaires incohérents.']], 422, 'VALIDATION_ECHOUEE');
                    }
                    $hours[$day] = $slot === null ? null : ['ouvre' => $slot['ouvre'], 'ferme' => $slot['ferme']];
                }
            }
            $settings['horaires_ouverture'] = $hours;
        }
        if (array_key_exists('fidelite', $data)) {
            $settings['fidelite'] = $data['fidelite'] === null ? null : [
                'montant_par_point' => (string) $data['fidelite']['montant_par_point'],
                'valeur_point' => (string) $data['fidelite']['valeur_point'],
            ];
        }
        $store->forceFill(['parametres' => $settings])->save();

        return $this->success($this->presentSettings($store->refresh()), 'Réglages du salon enregistrés.');
    }

    /** GET commissions?employe_id=&du=&au= — dues (toutes) et payées / générées sur la période, par employé. */
    public function commissions(Store $store, Request $request)
    {
        abort_unless($request->user()->can('employes.voir'), 403);
        $request->validate(['employe_id' => ['nullable', 'integer'], 'du' => ['nullable', 'date'], 'au' => ['nullable', 'date']]);
        $from = $request->filled('du') ? $request->date('du')->utc() : now()->startOfMonth();
        $to = $request->filled('au') ? $request->date('au')->utc() : now();

        $employees = Employee::withTrashed()
            ->when($request->filled('employe_id'), fn ($q) => $q->whereKey($request->integer('employe_id')))
            ->whereIn('id', Commission::query()->select('employe_id'))
            ->orderBy('nom')->get();
        $rows = $employees->map(function (Employee $employee) use ($from, $to) {
            $base = Commission::query()->where('employe_id', $employee->id);

            return [
                'employe' => ['id' => $employee->id, 'nom' => $employee->nom, 'taux_commission' => $employee->taux_commission],
                'du' => Money::round((string) (clone $base)->where('statut', 'due')->sum('montant')),
                'generees_periode' => Money::round((string) (clone $base)->whereIn('statut', ['due', 'payee'])->where('montant', '>', 0)->whereBetween('created_at', [$from, $to])->sum('montant')),
                'payees_periode' => Money::round((string) (clone $base)->where('statut', 'payee')->whereBetween('updated_at', [$from, $to])->sum('montant')),
                'prestations_periode' => (clone $base)->where('montant', '>', 0)->whereIn('statut', ['due', 'payee'])->whereBetween('created_at', [$from, $to])->count(),
                'dernieres' => (clone $base)->with(['service:id,nom', 'sale:id,reference'])->latest()->limit(20)->get()->map(fn (Commission $c) => [
                    'id' => $c->id,
                    'service' => $c->service?->nom,
                    'vente' => $c->sale?->reference,
                    'vente_id' => $c->vente_id,
                    'base' => $c->base,
                    'mode' => $c->mode,
                    'valeur' => $c->valeur,
                    'montant' => $c->montant,
                    'statut' => $c->statut,
                    'le' => $c->created_at,
                ])->values(),
            ];
        })->values();

        return $this->success($rows);
    }

    /** POST commissions/payer { employe_id, mode, caisse_id? } — paiement de type commission par la paie. */
    public function payCommissions(Store $store, Request $request)
    {
        abort_unless($request->user()->can('employes.gerer'), 403);
        $data = $request->validate([
            'employe_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('employes')],
            'mode' => ['required', 'in:caisse,externe'],
            'caisse_id' => ['nullable', 'integer', 'required_if:mode,caisse', TenantScopedRules::existsInCurrentStore('caisses')],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $payment = $this->commissions->pay(Employee::query()->findOrFail($data['employe_id']), $data, $request->user()?->id);
        } catch (SalonException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        } catch (CashRegisterNotOpenException $e) {
            return $this->error($e->getMessage(), [], 422, 'AUCUNE_SESSION_CAISSE_OUVERTE');
        }

        return $this->success(['paiement_id' => $payment->id, 'montant' => $payment->montant], 'Commissions payées.');
    }

    /** GET clients/{customer}/fidelite — solde et historique. */
    public function loyalty(Store $store, Customer $customer, Request $request)
    {
        abort_unless($request->user()->can('clients.voir'), 403);
        $movements = LoyaltyMovement::query()->where('client_id', $customer->id)->latest()->limit(100)->get();
        $settings = SalonSettings::loyalty($store);

        return $this->success([
            'points' => $customer->points_fidelite,
            'valeur' => $settings === null ? null : bcmul((string) $customer->points_fidelite, $settings['valeur_point'], 2),
            'bareme' => $settings,
            'mouvements' => $movements->map(fn (LoyaltyMovement $m) => [
                'id' => $m->id, 'type' => $m->type, 'points' => $m->points, 'solde_apres' => $m->solde_apres,
                'valeur' => $m->valeur, 'note' => $m->note, 'vente_id' => $m->vente_id, 'le' => $m->created_at,
            ])->values(),
        ]);
    }

    /** POST clients/{customer}/fidelite/ajustement { points (±), note } */
    public function adjustLoyalty(Store $store, Customer $customer, Request $request)
    {
        abort_unless($request->user()->can('clients.modifier'), 403);
        $data = $request->validate(['points' => ['required', 'integer', 'not_in:0', 'between:-100000,100000'], 'note' => ['required', 'string', 'max:255']]);
        DB::transaction(fn () => $this->loyalty->adjust($customer, (int) $data['points'], $data['note'], $request->user()?->id));

        return $this->loyalty($store, $customer->refresh(), $request);
    }

    /** @return array<string, mixed> */
    private function presentSettings(Store $store): array
    {
        return [
            'horaires_ouverture' => SalonSettings::openingHours($store),
            'fidelite' => SalonSettings::loyalty($store),
            'fuseau_horaire' => SalonSettings::timezone($store),
        ];
    }
}
