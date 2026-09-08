<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Http\Requests\CreateBusinessRequest;
use App\Modules\Tenancy\Http\Resources\BusinessResource;
use App\Modules\Tenancy\Models\Business;
use App\Modules\Tenancy\Services\BusinessService;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;

class BusinessController extends ApiController
{
    public function __construct(private readonly BusinessService $businessService) {}

    public function index(Request $request)
    {
        $businesses = Business::whereHas('businessUsers', fn ($q) => $q->where('user_id', $request->user()->id))->get();

        return $this->success(BusinessResource::collection($businesses));
    }

    public function store(CreateBusinessRequest $request)
    {
        $business = $this->businessService->createForOwner($request->user(), $request->validated());

        return $this->success(new BusinessResource($business), 'Entreprise créée avec succès.', [], 201);
    }

    public function show(Business $business)
    {
        $this->authorize('view', $business);

        return $this->success(new BusinessResource($business));
    }
}
