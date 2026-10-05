<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['reference', 'type', 'statut', 'table_id', 'client_id', 'nom_client', 'telephone_client', 'adresse_livraison', 'date_promise', 'note', 'vente_id', 'motif_annulation', 'cree_par_id'])]
#[Table('commandes')]
class Order extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return [
            'type' => OrderType::class,
            'statut' => OrderStatus::class,
            'date_promise' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'table_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'client_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'commande_id');
    }

    /** Total indicatif (prix au moment de la commande). */
    public function total(): string
    {
        return $this->items->reduce(fn (string $carry, OrderItem $item) => bcadd($carry, $item->lineTotal(), 2), '0.00');
    }
}
