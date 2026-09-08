<?php

namespace App\Shared\Validation;

use App\Shared\Tenancy\Contracts\TenantContextContract;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Couche 5 of docs/multi-tenancy.md: every FormRequest field that
 * references another tenant-scoped resource by id (customer_id,
 * product_id, employee_id, ...) MUST use this instead of a bare
 * `exists:table,column` — otherwise a request for Store A can silently
 * attach a resource belonging to Store B (see docs/multi-tenancy.md §3
 * "Couche 5" for the concrete attack this closes).
 *
 * No FormRequest in this phase has such a field yet (StoreUser targets a
 * platform-wide User, which is not tenant-scoped, and Business/Store
 * creation don't reference tenant-scoped ids either) — this is the
 * reusable mechanism for the modules that will need it (Sales, Orders,
 * Appointments, ...), covered by a unit test against a real tenant-scoped
 * table (store_users) so it's proven correct before anything depends on it.
 *
 * Usage in a future FormRequest:
 *   'customer_id' => ['required', TenantScopedRules::existsInCurrentStore('customers')],
 */
final class TenantScopedRules
{
    public static function existsInCurrentStore(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)
            ->where('store_id', app(TenantContextContract::class)->getStoreId());
    }

    /**
     * For columns that must be unique per store (sku, barcode, slug on a
     * catalog table, ...), not globally — a bare `unique:products,sku`
     * would wrongly reject "SKU-1" in Store B just because Store A
     * already used it. First real consumer: Catalog (Phase 3).
     */
    public static function uniqueInCurrentStore(string $table, ?string $column = null): Unique
    {
        return Rule::unique($table, $column)
            ->where('store_id', app(TenantContextContract::class)->getStoreId());
    }
}
