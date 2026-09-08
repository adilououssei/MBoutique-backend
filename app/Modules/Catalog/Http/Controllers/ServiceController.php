<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Requests\CreateServiceRequest;
use App\Modules\Catalog\Http\Requests\UpdateServiceRequest;
use App\Modules\Catalog\Http\Resources\ServiceResource;
use App\Modules\Catalog\Models\Service;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;

class ServiceController extends ApiController
{
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Service::class, $store]);

        $services = Service::query()
            ->with('category')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return $this->success(ServiceResource::collection($services));
    }

    public function store(Store $store, CreateServiceRequest $request)
    {
        $this->authorize('create', [Service::class, $store]);

        $service = Service::create($request->validated());

        return $this->success(new ServiceResource($service->load('category')), 'Service créé avec succès.', [], 201);
    }

    public function show(Store $store, Service $service)
    {
        $this->authorize('view', [$service, $store]);

        return $this->success(new ServiceResource($service->load('category')));
    }

    public function update(Store $store, Service $service, UpdateServiceRequest $request)
    {
        $this->authorize('update', [$service, $store]);

        $service->update($request->validated());

        return $this->success(new ServiceResource($service->load('category')), 'Service mis à jour.');
    }

    public function destroy(Store $store, Service $service)
    {
        $this->authorize('delete', [$service, $store]);

        $service->delete();

        return $this->success(null, 'Service supprimé.');
    }
}
