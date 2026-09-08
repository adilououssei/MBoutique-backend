<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Models\User;
use App\Modules\Tenancy\Http\Requests\AddBusinessUserRequest;
use App\Modules\Tenancy\Models\Business;
use App\Modules\Tenancy\Models\BusinessUser;
use App\Modules\Tenancy\Services\StoreService;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Support\Facades\DB;

class BusinessUserController extends ApiController
{
    public function __construct(private readonly StoreService $storeService) {}

    public function store(Business $business, AddBusinessUserRequest $request)
    {
        $this->authorize('manageMembers', $business);

        $targetUser = User::where('email', $request->validated('email'))->firstOrFail();
        $role = $request->validated('role');

        $businessUser = DB::transaction(function () use ($business, $targetUser, $role) {
            $businessUser = BusinessUser::updateOrCreate(
                ['business_id' => $business->id, 'user_id' => $targetUser->id],
                ['role' => $role],
            );

            // Access to a Business's existing stores must not depend on
            // whether the Store or the BusinessUser row was created first.
            $this->storeService->grantAccessToAllStores($business, $targetUser, $role);

            return $businessUser;
        });

        return $this->success([
            'id' => $businessUser->id,
            'user_id' => $targetUser->id,
            'role' => $businessUser->role->value,
        ], "Utilisateur ajouté à l'entreprise.", [], 201);
    }
}
