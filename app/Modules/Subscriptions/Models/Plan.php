<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Features\Models\Feature;
use Database\Factories\Modules\Subscriptions\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
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
#[Fillable(['code', 'nom', 'prix_mensuel', 'prix_annuel', 'max_boutiques', 'max_utilisateurs_par_boutique', 'max_produits_par_boutique'])]
#[Table('forfaits')]
class Plan extends Model
{
    use HasFactory;

    protected $attributes = [
        'actif' => true,
    ];

    protected static function newFactory(): PlanFactory
    {
        return PlanFactory::new();
    }

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'prix_mensuel' => 'decimal:2',
            'prix_annuel' => 'decimal:2',
        ];
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'fonctionnalites_forfait', 'forfait_id', 'fonctionnalite_id');
    }
}
