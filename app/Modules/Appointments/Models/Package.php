<?php

namespace App\Modules\Appointments\Models;

use App\Modules\Catalog\Models\Service;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Forfait / abonnement du salon : N séances des prestations incluses, prix, validité en jours. */
#[Fillable(['nom', 'description', 'prix', 'nombre_seances', 'validite_jours', 'actif'])]
#[Table('forfaits_prestations')]
class Package extends Model
{
    use BelongsToStore, SoftDeletes;

    protected $attributes = ['actif' => true];

    protected function casts(): array
    {
        return ['prix' => 'decimal:2', 'nombre_seances' => 'integer', 'validite_jours' => 'integer', 'actif' => 'boolean'];
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'services_forfait', 'forfait_id', 'service_id')->withTimestamps();
    }
}
