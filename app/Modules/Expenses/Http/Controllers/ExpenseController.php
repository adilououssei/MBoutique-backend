<?php

namespace App\Modules\Expenses\Http\Controllers;

use App\Modules\CashRegister\Exceptions\InsufficientCashException;
use App\Modules\Employees\Models\EmployeePayment;
use App\Modules\Expenses\Enums\ExpenseCategory;
use App\Modules\Expenses\Exceptions\CashRegisterNotOpenException;
use App\Modules\Expenses\Exceptions\ExpenseAlreadyCancelledException;
use App\Modules\Expenses\Http\Requests\CreateExpenseRequest;
use App\Modules\Expenses\Http\Resources\ExpenseResource;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Services\ExpenseService;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use App\Shared\Validation\TenantScopedRules;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ExpenseController extends ApiController
{
    public function __construct(private readonly ExpenseService $expenses) {}

    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Expense::class, $store]);
        [$from, $to] = $this->period($request);

        $expenses = Expense::query()
            ->with('createdBy')
            ->whereBetween('depense_le', [$from, $to])
            ->when($request->filled('categorie'), fn ($q) => $q->where('categorie', $request->string('categorie')))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->latest('depense_le')
            ->latest('id')
            ->paginate(min((int) $request->integer('par_page', 30), 100));

        return $this->success(ExpenseResource::collection($expenses));
    }

    /**
     * GET /depenses/resume — total de la période par catégorie (dépenses
     * annulées exclues). Les salaires versés via le module Employés sont
     * ajoutés à part, lus, jamais ressaisis : pas de double compte.
     */
    public function summary(Store $store, Request $request, FeatureGate $features)
    {
        $this->authorize('viewAny', [Expense::class, $store]);
        [$from, $to] = $this->period($request);

        $rows = Expense::query()
            ->where('statut', Expense::VALID)
            ->whereBetween('depense_le', [$from, $to])
            ->selectRaw('categorie, COUNT(*) as nombre, SUM(montant) as total')
            ->groupBy('categorie')
            ->get();
        $byCategory = $rows->map(fn ($row) => [
            'categorie' => $row->categorie->value,
            'libelle' => $row->categorie->label(),
            'nombre' => (int) $row->nombre,
            'total' => number_format((float) $row->total, 2, '.', ''),
        ])->sortByDesc(fn ($row) => (float) $row['total'])->values();
        $expensesTotal = number_format((float) $rows->sum('total'), 2, '.', '');

        $salaries = $features->allows($store, 'employes')
            ? number_format((float) EmployeePayment::query()->whereBetween('paye_le', [$from, $to])->sum('montant'), 2, '.', '')
            : null;

        return $this->success([
            'periode' => ['du' => $from->toIso8601String(), 'au' => $to->toIso8601String()],
            'total_depenses' => $expensesTotal,
            'salaires_employes' => $salaries,
            'total' => bcadd($expensesTotal, $salaries ?? '0', 2),
            'par_categorie' => $byCategory,
            'categories' => collect(ExpenseCategory::cases())->map(fn (ExpenseCategory $c) => ['valeur' => $c->value, 'libelle' => $c->label()]),
        ]);
    }

    public function store(Store $store, CreateExpenseRequest $request)
    {
        $this->authorize('create', [Expense::class, $store]);

        return $this->guard(function () use ($request) {
            $expense = $this->expenses->record($request->validated(), $request->user()?->id);

            return $this->success(new ExpenseResource($expense->load('createdBy')), 'Dépense enregistrée.', [], $expense->wasRecentlyCreated ? 201 : 200);
        });
    }

    public function show(Store $store, Expense $expense)
    {
        $this->authorize('view', [$expense, $store]);

        return $this->success(new ExpenseResource($expense->load('createdBy')));
    }

    /** POST /depenses/{id}/justificatif — photo du reçu (disque privé, servie par l'API). */
    public function uploadReceipt(Store $store, Expense $expense, Request $request)
    {
        $this->authorize('create', [Expense::class, $store]);
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        if ($expense->justificatif) {
            Storage::disk('local')->delete($expense->justificatif);
        }
        $file = $request->file('image');
        $expense->update(['justificatif' => $file->storeAs("depenses/{$store->id}", Str::uuid().'.'.$file->extension(), 'local')]);

        return $this->success(new ExpenseResource($expense->load('createdBy')), 'Justificatif enregistré.');
    }

    /** GET /depenses/{id}/justificatif — le fichier, avec les droits de lecture de la dépense. */
    public function receipt(Store $store, Expense $expense)
    {
        $this->authorize('view', [$expense, $store]);
        abort_unless($expense->justificatif && Storage::disk('local')->exists($expense->justificatif), 404);

        return Storage::disk('local')->response($expense->justificatif);
    }

    /** POST /depenses/{id}/annuler — l'argent payé en caisse y revient. */
    public function cancel(Store $store, Expense $expense, Request $request)
    {
        $this->authorize('cancel', [$expense, $store]);
        $data = $request->validate([
            'motif' => ['required', 'string', 'max:500'],
            'caisse_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
        ]);

        return $this->guard(fn () => $this->success(
            new ExpenseResource($this->expenses->cancel($expense, $data['motif'], $data['caisse_id'] ?? null, $request->user()?->id)->load('createdBy')),
            'Dépense annulée.',
        ));
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(Request $request): array
    {
        $request->validate(['du' => ['nullable', 'date'], 'au' => ['nullable', 'date', 'after_or_equal:du'], 'categorie' => ['nullable', Rule::enum(ExpenseCategory::class)]]);

        return [
            $request->filled('du') ? $request->date('du')->startOfDay() : now()->startOfMonth(),
            $request->filled('au') ? $request->date('au')->endOfDay() : now()->endOfDay(),
        ];
    }

    private function guard(Closure $operation)
    {
        try {
            return $operation();
        } catch (CashRegisterNotOpenException $e) {
            return $this->error($e->getMessage(), [], 422, 'AUCUNE_SESSION_CAISSE_OUVERTE');
        } catch (InsufficientCashException $e) {
            return $this->error($e->getMessage(), [], 422, 'SOLDE_CAISSE_INSUFFISANT');
        } catch (ExpenseAlreadyCancelledException $e) {
            return $this->error($e->getMessage(), [], 422, 'DEPENSE_DEJA_ANNULEE');
        }
    }
}
