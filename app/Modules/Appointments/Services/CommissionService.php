<?php

namespace App\Modules\Appointments\Services;

use App\Modules\Appointments\Exceptions\SalonException;
use App\Modules\Appointments\Models\Commission;
use App\Modules\Catalog\Models\Service;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeePayment;
use App\Modules\Employees\Services\EmployeePaymentService;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sales\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Commissions (option du salon) : calculées une fois à l'encaissement, par
 * ligne de prestation ayant un employé — règle de la prestation (% ou
 * montant fixe) sinon taux de l'employé. Annulées avec la vente ; payées
 * par la paie existante (paiement de type `commission`).
 */
class CommissionService
{
    public function __construct(private readonly EmployeePaymentService $payroll) {}

    public function recordForSale(Sale $sale): void
    {
        $items = SaleItem::query()->where('vente_id', $sale->id)->whereNotNull('service_id')->whereNotNull('employe_id')->get();
        foreach ($items as $item) {
            $service = Service::withTrashed()->find($item->service_id);
            $employee = Employee::withTrashed()->find($item->employe_id);
            [$mode, $value] = $service?->commission_type !== null && $service->commission_valeur !== null
                ? [$service->commission_type, (string) $service->commission_valeur]
                : ['pourcentage', (string) ($employee?->taux_commission ?? '0')];
            $base = (string) $item->montant_total;
            $amount = $mode === 'montant'
                ? Money::round(bcmul($value, (string) $item->quantite, 4))
                : Money::round(bcdiv(bcmul($base, $value, 6), '100', 6));
            if (bccomp($amount, '0', 2) <= 0) {
                continue;
            }
            Commission::create([
                'employe_id' => $item->employe_id,
                'vente_id' => $sale->id,
                'ligne_vente_id' => $item->id,
                'service_id' => $item->service_id,
                'base' => $base,
                'mode' => $mode,
                'valeur' => $value,
                'montant' => $amount,
            ]);
        }
    }

    /** Vente annulée : commission due → annulée ; déjà payée → ligne négative à déduire de la prochaine paie. */
    public function reverseForSale(Sale $sale): void
    {
        foreach (Commission::query()->where('vente_id', $sale->id)->where('montant', '>', 0)->get() as $commission) {
            if ($commission->statut === 'due') {
                $commission->update(['statut' => 'annulee']);
            } elseif ($commission->statut === 'payee') {
                Commission::create([
                    ...$commission->only(['employe_id', 'vente_id', 'ligne_vente_id', 'service_id', 'base', 'mode', 'valeur']),
                    'montant' => bcmul((string) $commission->montant, '-1', 2),
                ]);
            }
        }
    }

    /**
     * Paie les commissions dues de l'employé (solde net, déductions
     * comprises) par un paiement de type `commission`.
     *
     * @param  array{mode: string, caisse_id?: int|null, note?: string|null}  $data
     */
    public function pay(Employee $employee, array $data, ?int $userId): EmployeePayment
    {
        return DB::transaction(function () use ($employee, $data, $userId) {
            $due = Commission::query()->where('employe_id', $employee->id)->where('statut', 'due')->lockForUpdate()->get();
            $total = $due->reduce(fn (string $carry, Commission $c) => bcadd($carry, (string) $c->montant, 2), '0.00');
            if (bccomp($total, '0', 2) <= 0) {
                throw new SalonException("Aucune commission à payer pour {$employee->nom}.", 'AUCUNE_COMMISSION');
            }
            $payment = $this->payroll->pay($employee, [
                'type' => 'commission',
                'montant' => $total,
                'mode' => $data['mode'],
                'caisse_id' => $data['caisse_id'] ?? null,
                'periode' => 'Commissions au '.now()->format('d/m/Y'),
                'note' => $data['note'] ?? "{$due->count()} prestation(s)",
            ], $userId);
            Commission::query()->whereIn('id', $due->pluck('id'))->update(['statut' => 'payee', 'paiement_employe_id' => $payment->id]);

            return $payment;
        });
    }
}
