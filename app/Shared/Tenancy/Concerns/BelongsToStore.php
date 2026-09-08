<?php

namespace App\Shared\Tenancy\Concerns;

use App\Shared\Tenancy\Contracts\TenantContextContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applied to every tenant-scoped Eloquent model (Product, Sale, Customer,
 * Employee, ...). Guarantees that a query can never leak rows across
 * stores, even if a developer forgets an explicit ->where('store_id', ...)
 * somewhere. See docs/multi-tenancy.md "Defence in depth" section.
 *
 * Requires a `store_id` column on the model's table.
 */
trait BelongsToStore
{
    public static function bootBelongsToStore(): void
    {
        static::addGlobalScope('store', function (Builder $builder) {
            $storeId = app(TenantContextContract::class)->getStoreId();

            if ($storeId !== null) {
                $builder->where($builder->getModel()->getTable().'.store_id', $storeId);
            }
        });

        static::creating(function ($model) {
            $storeId = app(TenantContextContract::class)->getStoreId();

            // Always override with the resolved tenant, even if a store_id was
            // mass-assigned from request input: a client-supplied store_id must
            // never be able to attach a row to a store the requester isn't
            // scoped to. See docs/multi-tenancy.md "IDOR par store_id assigné en masse".
            if ($storeId !== null) {
                $model->store_id = $storeId;
            } elseif (empty($model->store_id)) {
                throw new \RuntimeException(
                    static::class.' uses BelongsToStore but no tenant is resolved. '
                    .'Set TenantContext::setStoreId() before creating this model (e.g. from a console command or job).'
                );
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('store_id')) {
                throw new \RuntimeException(
                    'store_id is immutable on '.static::class.'. Moving a record between stores must go '
                    .'through a dedicated, explicitly authorized service method, never a regular update.'
                );
            }
        });
    }

    public function scopeWithoutStoreScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('store');
    }
}
