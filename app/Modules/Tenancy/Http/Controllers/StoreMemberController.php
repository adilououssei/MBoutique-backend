<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Models\User;
use App\Modules\Tenancy\Enums\StoreUserStatus;
use App\Modules\Tenancy\Http\Requests\AddStoreMemberRequest;
use App\Modules\Tenancy\Http\Resources\StoreUserResource;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\StoreUser;
use App\Modules\Tenancy\Services\StoreMembershipService;
use App\Shared\Http\Controllers\ApiController;

/**
 * Authorization here is via spatie permissions (store_users.view/manage),
 * checked by the 'permission:*' route middleware — not a Policy, since
 * this isn't "does this belong to the right store" (StoreUser::store_id
 * already guarantees that via BelongsToStore) but "is this action allowed
 * for this role", which is exactly what permissions answer.
 */
class StoreMemberController extends ApiController
{
    public function __construct(private readonly StoreMembershipService $membershipService) {}

    public function index(Store $store)
    {
        $members = StoreUser::with('user')
            ->where('status', StoreUserStatus::Active->value)
            ->get();

        return $this->success(StoreUserResource::collection($members));
    }

    public function store(Store $store, AddStoreMemberRequest $request)
    {
        $targetUser = User::where('email', $request->validated('email'))->firstOrFail();

        $storeUser = $this->membershipService->addMember(
            $store,
            $targetUser,
            $request->validated('role'),
            $request->user(),
        );

        return $this->success(
            new StoreUserResource($storeUser->load('user')),
            'Membre ajouté à la boutique.',
            [],
            201
        );
    }

    public function destroy(Store $store, User $user)
    {
        $this->membershipService->removeMember($store, $user);

        return $this->success(null, 'Accès révoqué.');
    }
}
