<?php

namespace App\Modules\Customers\Models;

use App\Models\User;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['numero', 'client_id', 'nom_patient', 'prescripteur', 'etablissement', 'prescrite_le', 'photo', 'note', 'statut', 'cree_par_id'])]
#[Table('ordonnances')]
class Prescription extends Model
{
    use BelongsToStore;

    protected $attributes = ['statut' => 'en_attente'];

    protected function casts(): array
    {
        return ['prescrite_le' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PrescriptionLine::class, 'ordonnance_id')->orderBy('id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PrescriptionDelivery::class, 'ordonnance_id')->latest('id');
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
