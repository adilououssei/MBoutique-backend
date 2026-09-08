<?php

namespace App\Modules\Tenancy\Services;

use App\Models\User;
use App\Modules\Tenancy\Enums\BusinessUserRole;
use App\Modules\Tenancy\Models\Business;
use App\Modules\Tenancy\Models\BusinessUser;
use Illuminate\Support\Facades\DB;

class BusinessService
{
    /**
     * Creating a Business and granting its creator the "owner"
     * BusinessUser role is one action: both rows exist, or neither does.
     * See docs/audit-2026-09.md §7 / docs/database.md §2.
     */
    public function createForOwner(User $owner, array $data): Business
    {
        return DB::transaction(function () use ($owner, $data) {
            // owner_user_id is deliberately not $fillable (it must always
            // be server-derived, never client input) — forceFill() is the
            // correct escape hatch for this service's own trusted value,
            // as opposed to widening $fillable for everyone.
            $business = new Business($data);
            $business->forceFill(['owner_user_id' => $owner->id]);
            $business->save();

            BusinessUser::create([
                'business_id' => $business->id,
                'user_id' => $owner->id,
                'role' => BusinessUserRole::Owner,
            ]);

            return $business;
        });
    }
}
