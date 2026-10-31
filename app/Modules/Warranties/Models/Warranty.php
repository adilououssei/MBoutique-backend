<?php

namespace App\Modules\Warranties\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Devices\Models\Device;
use App\Modules\Sales\Models\Sale;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['numero', 'vente_id', 'ligne_vente_id', 'produit_id', 'appareil_id', 'client_id', 'nom_produit', 'quantite', 'duree_mois', 'debut_le', 'fin_le', 'conditions', 'statut', 'annulee_le'])]
#[Table('garanties')]
class Warranty extends Model
{
    use BelongsToStore;

    public const VALID = 'valide';

    public const CANCELLED = 'annulee';

    protected function casts(): array
    {
        return ['quantite' => 'decimal:3', 'duree_mois' => 'integer', 'debut_le' => 'date', 'fin_le' => 'date', 'annulee_le' => 'datetime'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'vente_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produit_id')->withTrashed();
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'appareil_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'client_id')->withTrashed();
    }

    public function claims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class, 'garantie_id');
    }

    /** `active`, `expiree` ou `annulee`. */
    public function state(): string
    {
        if ($this->statut === self::CANCELLED) {
            return 'annulee';
        }

        return $this->fin_le->lt(now()->startOfDay()) ? 'expiree' : 'active';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('statut', self::VALID)->whereDate('fin_le', '>=', now()->toDateString());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('statut', self::VALID)->whereDate('fin_le', '<', now()->toDateString());
    }
}
