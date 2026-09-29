<?php

namespace App\Modules\Features\Models;

use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lets one Store deviate from its BusinessDomain's default for one
 * Feature (enable something the domain doesn't include, or disable
 * something it does). Tenant-scoped: uses BelongsToStore so Store A can
 * never read or write Store B's overrides (docs/features.md §5,
 * docs/multi-tenancy.md).
 */
#[Fillable(['boutique_id', 'fonctionnalite_id', 'activee'])]
#[Table('fonctionnalites_boutique')]
class StoreFeatureOverride extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return [
            'activee' => 'boolean',
        ];
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'fonctionnalite_id');
    }
}
