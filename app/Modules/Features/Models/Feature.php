<?php

namespace App\Modules\Features\Models;

use Database\Factories\Modules\Features\FeatureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A capability that can be switched on/off for a Store (products,
 * appointments, cash_register, ...). Deliberately business-agnostic:
 * a Feature never contains logic specific to one BusinessDomain — the
 * mapping lives entirely in DomainFeature. See docs/features.md.
 */
#[Fillable(['nom', 'slug', 'description', 'actif'])]
#[Table('fonctionnalites')]
class Feature extends Model
{
    use HasFactory;

    protected $attributes = [
        'actif' => true,
    ];

    protected static function newFactory(): FeatureFactory
    {
        return FeatureFactory::new();
    }

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }

    public function domainFeatures(): HasMany
    {
        return $this->hasMany(DomainFeature::class, 'fonctionnalite_id');
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(FeatureDependency::class, 'fonctionnalite_id');
    }
}
