<?php

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\Customers\Http\Requests\CreateCustomerRequest;
use App\Modules\Customers\Http\Requests\UpdateCustomerRequest;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;

class CustomerController extends ApiController
{
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Customer::class, $store]);

        $customers = Customer::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('company_name', 'like', $term);
                });
            })
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return $this->success(CustomerResource::collection($customers));
    }

    public function store(Store $store, CreateCustomerRequest $request)
    {
        $this->authorize('create', [Customer::class, $store]);

        $customer = Customer::create($request->validated());

        return $this->success(new CustomerResource($customer), 'Client créé avec succès.', [], 201);
    }

    public function show(Store $store, Customer $customer)
    {
        $this->authorize('view', [$customer, $store]);

        return $this->success(new CustomerResource($customer));
    }

    public function update(Store $store, Customer $customer, UpdateCustomerRequest $request)
    {
        $this->authorize('update', [$customer, $store]);

        $customer->update($request->validated());

        return $this->success(new CustomerResource($customer), 'Client mis à jour.');
    }

    public function destroy(Store $store, Customer $customer)
    {
        $this->authorize('delete', [$customer, $store]);

        $customer->delete();

        return $this->success(null, 'Client supprimé.');
    }
}
