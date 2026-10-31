<?php

namespace App\Modules\Employees\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Service;
use App\Modules\Employees\Enums\SalaryPeriod;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\Employees\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['utilisateur_id', 'nom', 'poste', 'specialites', 'telephone', 'adresse', 'date_embauche', 'salaire', 'periodicite_salaire', 'taux_commission', 'notes', 'actif'])]
#[Table('employes')]
class Employee extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'date_embauche' => 'date',
            'salaire' => 'decimal:2',
            'taux_commission' => 'decimal:2',
            'periodicite_salaire' => SalaryPeriod::class,
            'actif' => 'boolean',
        ];
    }

    protected static function newFactory(): EmployeeFactory
    {
        return EmployeeFactory::new();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    /** Compte applicatif lié (facultatif). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(EmployeePayment::class, 'employe_id');
    }

    /** Salon : prestations que l'employé est habilité à faire (aucune = toutes). */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'services_employes', 'employe_id', 'service_id')->withTimestamps();
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(EmployeeSchedule::class, 'employe_id');
    }

    public function absences(): HasMany
    {
        return $this->hasMany(EmployeeAbsence::class, 'employe_id');
    }
}
