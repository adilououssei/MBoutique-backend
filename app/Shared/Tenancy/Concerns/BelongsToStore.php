<?php

namespace App\Shared\Tenancy\Concerns;

use App\Shared\Tenancy\Contracts\TenantContextContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applied to every tenant-scoped Eloquent model (Product, Sale, Customer,
 * Employee, ...). Guarantees that a query can never leak rows across
 * stores, even if a developer forgets an explicit ->where('boutique_id', ...)
 * somewhere. See docs/multi-tenancy.md "Defence in depth" section.
 *
 * Requires a `boutique_id` column on the model's table.
 */
trait BelongsToStore
{
    public static function bootBelongsToStore(): void
    {
        static::addGlobalScope('store', function (Builder $builder) {
            $storeId = app(TenantContextContract::class)->getStoreId();

            if ($storeId !== null) {
                $builder->where($builder->getModel()->getTable().'.boutique_id', $storeId);
            }
        });

        static::creating(function ($model) {
            $storeId = app(TenantContextContract::class)->getStoreId();

            // Always override with the resolved tenant, even if a boutique_id was
            // mass-assigned from request input: a client-supplied boutique_id must
            // never be able to attach a row to a store the requester isn't
            // scoped to. See docs/multi-tenancy.md "IDOR par store_id assigné en masse".
            if ($storeId !== null) {
                $model->boutique_id = $storeId;
            } elseif (empty($model->boutique_id)) {
                throw new \RuntimeException(
                    static::class." utilise BelongsToStore mais aucune boutique n'est résolue. "
                    .'Appelez TenantContext::setStoreId() avant de créer ce modèle (par exemple depuis une commande console ou un job).'
                );
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('boutique_id')) {
                throw new \RuntimeException(
                    'boutique_id est immuable sur '.static::class.'. Déplacer un enregistrement entre boutiques doit passer '
                    .'par une méthode de service dédiée et explicitement autorisée, jamais par une mise à jour ordinaire.'
                );
            }
        });
    }

    public function scopeWithoutStoreScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('store');
    }
}
