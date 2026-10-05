<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Modules\CashRegister\Exceptions\InsufficientCashException;
use App\Modules\Employees\Exceptions\CashRegisterNotOpenException;
use App\Modules\Employees\Http\Requests\EmployeeRequest;
use App\Modules\Employees\Http\Requests\PayEmployeeRequest;
use App\Modules\Employees\Http\Resources\EmployeePaymentResource;
use App\Modules\Employees\Http\Resources\EmployeeResource;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeePayment;
use App\Modules\Employees\Services\EmployeePaymentService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class EmployeeController extends ApiController
{
    public function __construct(private readonly EmployeePaymentService $payments) {}

    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Employee::class, $store]);

        $employees = $this->withMonthTotal(Employee::query())
            ->with('user')
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $term = '%'.$request->string('recherche').'%';
                $q->where(fn ($q) => $q->where('nom', 'like', $term)->orWhere('poste', 'like', $term)->orWhere('telephone', 'like', $term));
            })
            ->when($request->has('actif'), fn ($q) => $q->where('actif', $request->boolean('actif')))
            ->orderBy('nom')
            ->paginate(min((int) $request->integer('par_page', 20), 100));

        return $this->success(EmployeeResource::collection($employees));
    }

    public function store(Store $store, EmployeeRequest $request)
    {
        $this->authorize('create', [Employee::class, $store]);

        $employee = Employee::create($request->validated());

        return $this->success(new EmployeeResource($employee->load('user')), 'Employé ajouté.', [], 201);
    }

    public function show(Store $store, Employee $employee)
    {
        $this->authorize('view', [$employee, $store]);

        return $this->success(new EmployeeResource($this->withMonthTotal(Employee::query())->with('user')->findOrFail($employee->id)));
    }

    public function update(Store $store, Employee $employee, EmployeeRequest $request)
    {
        $this->authorize('manage', [$employee, $store]);

        $employee->update($request->validated());

        return $this->success(new EmployeeResource($employee->load('user')), 'Fiche employé mise à jour.');
    }

    public function destroy(Store $store, Employee $employee)
    {
        $this->authorize('manage', [$employee, $store]);

        $employee->delete();

        return $this->success(null, 'Employé supprimé.');
    }

    /** GET /employes/{employee}/paiements — historique, du plus récent au plus ancien. */
    public function payments(Store $store, Employee $employee, Request $request)
    {
        $this->authorize('view', [$employee, $store]);

        $payments = EmployeePayment::query()
            ->where('employe_id', $employee->id)
            ->with('createdBy')
            ->latest('paye_le')
            ->latest('id')
            ->paginate(min((int) $request->integer('par_page', 20), 100));

        return $this->success(EmployeePaymentResource::collection($payments));
    }

    /** POST /employes/{employee}/paiements — salaire, avance ou prime. */
    public function pay(Store $store, Employee $employee, PayEmployeeRequest $request)
    {
        $this->authorize('manage', [$employee, $store]);

        try {
            $payment = $this->payments->pay($employee, $request->validated(), $request->user()?->id);
        } catch (CashRegisterNotOpenException $e) {
            return $this->error($e->getMessage(), [], 422, 'AUCUNE_SESSION_CAISSE_OUVERTE');
        } catch (InsufficientCashException $e) {
            return $this->error($e->getMessage(), [], 422, 'SOLDE_CAISSE_INSUFFISANT');
        }

        return $this->success(new EmployeePaymentResource($payment->load('createdBy')), 'Paiement enregistré.', [], 201);
    }

    /** Somme versée depuis le début du mois en cours. */
    private function withMonthTotal(Builder $query): Builder
    {
        return $query->withSum(['payments as paye_ce_mois' => fn ($q) => $q->where('paye_le', '>=', now()->startOfMonth())], 'montant');
    }
}
