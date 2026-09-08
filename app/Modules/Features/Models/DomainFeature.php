<?php

namespace App\Modules\Features\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whether a Feature is ON by default for a given BusinessDomain. A
 * surrogate `id` primary key is used instead of the composite key
 * (business_domain_id, feature_id) described conceptually in
 * docs/database.md — Eloquent's composite-key support is limited enough
 * that a surrogate key + a unique constraint is the pragmatic choice; the
 * uniqueness guarantee is identical either way.
 */
#[Fillable(['business_domain_id', 'feature_id', 'is_default_enabled'])]
class DomainFeature extends Model
{
    protected function casts(): array
    {
        return [
            'is_default_enabled' => 'boolean',
        ];
    }

    public function businessDomain(): BelongsTo
    {
        return $this->belongsTo(BusinessDomain::class);
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }
}
