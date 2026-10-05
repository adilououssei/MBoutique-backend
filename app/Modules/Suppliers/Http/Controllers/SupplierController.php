<?php

namespace App\Modules\Suppliers\Http\Controllers;

use App\Modules\Suppliers\Http\Requests\CreateSupplierRequest;
use App\Modules\Suppliers\Http\Requests\UpdateSupplierRequest;
use App\Modules\Suppliers\Http\Resources\SupplierResource;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class SupplierController extends ApiController
{
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Supplier::class, $store]);

        $suppliers = $this->withTotals(Supplier::query())
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $term = '%'.$request->string('recherche').'%';
                $q->where(fn ($q) => $q->where('nom', 'like', $term)->orWhere('telephone', 'like', $term)->orWhere('nom_contact', 'like', $term));
            })
            ->when($request->has('actif'), fn ($q) => $q->where('actif', $request->boolean('actif')))
            ->orderBy('nom')
            ->paginate(min((int) $request->integer('par_page', 20), 100));

        return $this->success(SupplierResource::collection($suppliers));
    }

    public function store(Store $store, CreateSupplierRequest $request)
    {
        $this->authorize('create', [Supplier::class, $store]);

        $supplier = Supplier::create($request->validated());

        return $this->success(new SupplierResource($supplier), 'Fournisseur créé avec succès.', [], 201);
    }

    public function show(Store $store, Supplier $supplier)
    {
        $this->authorize('view', [$supplier, $store]);

        return $this->success(new SupplierResource($this->withTotals(Supplier::query())->findOrFail($supplier->id)));
    }

    public function update(Store $store, Supplier $supplier, UpdateSupplierRequest $request)
    {
        $this->authorize('update', [$supplier, $store]);

        $supplier->update($request->validated());

        return $this->success(new SupplierResource($supplier), 'Fournisseur mis à jour.');
    }

    public function destroy(Store $store, Supplier $supplier)
    {
        $this->authorize('delete', [$supplier, $store]);

        $supplier->delete();

        return $this->success(null, 'Fournisseur supprimé.');
    }

    /** Total des achats et total réglé, pour exposer le solde dû au fournisseur. */
    private function withTotals(Builder $query): Builder
    {
        return $query
            ->withSum('purchases as total_achats', 'montant_total')
            ->withSum('purchases as total_paye', 'montant_paye');
    }
}
