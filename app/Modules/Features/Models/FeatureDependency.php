<?php

namespace App\Modules\Features\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `feature_id` cannot be effectively active unless `depends_on_feature_id`
 * also resolves as active. Pure data, checked by FeatureGate. See
 * docs/features.md §7.
 */
#[Fillable(['feature_id', 'depends_on_feature_id'])]
class FeatureDependency extends Model
{
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'feature_id');
    }

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'depends_on_feature_id');
    }
}
