<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Features\Models\Feature;
use Database\Factories\Modules\Subscriptions\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A subscription tier. `features()` is an allow-list: if a plan has ZERO
 * rows here, FeatureGate treats it as "no plan-level restriction" (see
 * docs/subscriptions.md — a FREE plan can rely purely on the max_*
 * counters without listing every feature). `max_*` columns are limits
 * (counts), never confused with features (on/off) — see docs/subscriptions.md §3.
 */
#[Fillable(['code', 'name', 'price_monthly', 'price_yearly', 'max_stores', 'max_users_per_store', 'max_products_per_store'])]
class Plan extends Model
{
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function newFactory(): PlanFactory
    {
        return PlanFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
        ];
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'plan_features');
    }
}
