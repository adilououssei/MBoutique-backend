<?php

namespace App\Modules\Appointments\Models;

use App\Models\User;
use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['service_id', 'employe_id', 'client_id', 'nom_client', 'telephone_client', 'debut_le', 'fin_le', 'statut', 'notes', 'motif_annulation', 'vente_id', 'cree_par_id'])]
#[Table('rendez_vous')]
class Appointment extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return [
            'statut' => AppointmentStatus::class,
            'debut_le' => 'datetime',
            'fin_le' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id')->withTrashed();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employe_id')->withTrashed();
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
