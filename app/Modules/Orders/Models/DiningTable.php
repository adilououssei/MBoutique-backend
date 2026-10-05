<?php

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Table de la salle d'un restaurant. Libre/occupée est dérivé de openOrder(). */
#[Fillable(['nom', 'capacite', 'actif'])]
#[Table('tables_salle')]
class DiningTable extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return [
            'capacite' => 'integer',
            'actif' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    /** La commande ouverte sur cette table, s'il y en a une. */
    public function openOrder(): HasOne
    {
        return $this->hasOne(Order::class, 'table_id')->whereIn('statut', OrderStatus::openValues())->latestOfMany();
    }
}
