<?php

namespace App\Modules\Devices\Models;

use App\Modules\Catalog\Enums\ProductCondition;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Devices\Enums\DeviceStatus;
use App\Modules\Sales\Models\Sale;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['produit_id', 'imei', 'imei_2', 'numero_serie', 'statut', 'etat', 'cout_achat', 'ligne_achat_id', 'vente_id', 'ligne_vente_id', 'client_id', 'vendu_le', 'note'])]
#[Table('appareils')]
class Device extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return [
            'statut' => DeviceStatus::class,
            'etat' => ProductCondition::class,
            'cout_achat' => 'decimal:2',
            'vendu_le' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produit_id')->withTrashed();
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'vente_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'client_id')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeviceEvent::class, 'appareil_id');
    }

    /** Libellé court : l'IMEI, sinon le numéro de série. */
    public function identifier(): string
    {
        return $this->imei ?? $this->numero_serie ?? "#{$this->id}";
    }
}
