<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Enums\ReservationStatus;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['table_id', 'client_id', 'nom_client', 'telephone_client', 'prevue_le', 'nombre_personnes', 'statut', 'note', 'commande_id', 'cree_par_id'])]
#[Table('reservations')]
class Reservation extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return [
            'prevue_le' => 'datetime',
            'nombre_personnes' => 'integer',
            'statut' => ReservationStatus::class,
        ];
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
}
