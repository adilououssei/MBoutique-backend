<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Enums\StoreUserStatus;
use App\Modules\Tenancy\Http\Requests\CreateStoreRequest;
use App\Modules\Tenancy\Http\Resources\StoreResource;
use App\Modules\Tenancy\Models\Business;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\StoreUser;
use App\Modules\Tenancy\Services\StoreService;
use App\Shared\Http\Controllers\ApiController;
use App\Shared\Tenancy\Contracts\TenantContextContract;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StoreController extends ApiController
{
    public function __construct(
        private readonly StoreService $storeService,
        private readonly TenantContextContract $tenantContext,
    ) {}

    /**
     * GET /api/stores — "which stores can I act on", the store-switch
     * entry point for the mobile/React client. Deliberately has no
     * {store} in the URL and no 'store' middleware: TenantContext stays
     * unset for this request, so BelongsToStore's global scope on
     * StoreUser is a no-op and rows across every store are returned, as
     * intended. See docs/multi-tenancy.md §5.
     */
    public function mine(Request $request)
    {
        $storeUsers = StoreUser::with('store.businessDomain')
            ->where('user_id', $request->user()->id)
            ->where('status', StoreUserStatus::Active->value)
            ->get();

        $stores = $storeUsers->map(function (StoreUser $storeUser) use ($request) {
            $store = $storeUser->store;
            $store->setAttribute('my_role', $this->roleOnStore($request, $store));

            return $store;
        });

        return $this->success(StoreResource::collection($stores));
    }

    public function index(Business $business)
    {
        $this->authorize('view', $business);

        return $this->success(StoreResource::collection($business->stores()->with('businessDomain')->get()));
    }

    public function store(Business $business, CreateStoreRequest $request)
    {
        $this->authorize('createStore', $business);

        $data = $request->validated();
        $data['slug'] ??= Str::slug($data['name']).'-'.Str::lower(Str::random(6));

        $store = $this->storeService->createForBusiness($business, $data);

        return $this->success(new StoreResource($store->load('businessDomain')), 'Boutique créée avec succès.', [], 201);
    }

    public function show(Store $store)
    {
        $this->authorize('view', $store);

        return $this->success(new StoreResource($store->load('businessDomain')));
    }

    /**
     * Roles are spatie teams (store-scoped), so reading "my role" on a
     * store outside of a request already scoped to it means temporarily
     * pointing TenantContext there just for this lookup, then restoring
     * whatever it was — never leaving it pointed at a store this request
     * wasn't authorized against by the 'store' middleware.
     */
    private function roleOnStore(Request $request, Store $store): ?string
    {
        $previous = $this->tenantContext->getStoreId();
        $this->tenantContext->setStoreId($store->id);

        try {
            return $request->user()->getRoleNames()->first();
        } finally {
            $previous !== null ? $this->tenantContext->setStoreId($previous) : $this->tenantContext->clear();
        }
    }
}
