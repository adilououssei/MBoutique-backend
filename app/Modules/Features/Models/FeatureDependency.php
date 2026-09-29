<?php

namespace App\Modules\Features\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `fonctionnalite_id` cannot be effectively active unless `depend_de_fonctionnalite_id`
 * also resolves as active. Pure data, checked by FeatureGate. See
 * docs/features.md §7.
 */
#[Fillable(['fonctionnalite_id', 'depend_de_fonctionnalite_id'])]
#[Table('dependances_fonctionnalites')]
class FeatureDependency extends Model
{
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'fonctionnalite_id');
    }

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'depend_de_fonctionnalite_id');
    }
}
