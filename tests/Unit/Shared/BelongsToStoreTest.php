<?php

namespace Tests\Unit\Shared;

use App\Models\User;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\StoreUser;
use App\Shared\Tenancy\Contracts\TenantContextContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the CRITICAL fix made during the 2026-09-06 audit
 * (docs/audit-2026-09.md §3 / §12): store_id can never be dictated by
 * the caller, and is immutable once set. Uses StoreUser because it's the
 * one model in this phase with a real BelongsToStore-scoped store_id.
 */
class BelongsToStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_id_is_forced_from_tenant_context_even_if_a_different_value_is_mass_assigned(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $user = User::factory()->create();

        app(TenantContextContract::class)->setStoreId($storeA->id);

        $storeUser = StoreUser::create([
            'store_id' => $storeB->id, // attacker-controlled value, must be ignored
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $this->assertSame($storeA->id, $storeUser->store_id);
    }

    public function test_store_id_cannot_be_changed_after_creation(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();

        app(TenantContextContract::class)->setStoreId($storeA->id);
        $storeUser = StoreUser::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);

        $this->expectException(\RuntimeException::class);

        $storeUser->update(['store_id' => $storeB->id]);
    }

    public function test_creating_without_a_resolved_tenant_throws(): void
    {
        app(TenantContextContract::class)->clear();

        $this->expectException(\RuntimeException::class);

        StoreUser::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);
    }

    public function test_the_global_scope_only_returns_rows_of_the_current_store(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();

        app(TenantContextContract::class)->setStoreId($storeA->id);
        StoreUser::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);

        app(TenantContextContract::class)->setStoreId($storeB->id);
        StoreUser::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);

        $this->assertCount(1, StoreUser::all());
        $this->assertCount(2, StoreUser::withoutStoreScope()->get());
    }
}
