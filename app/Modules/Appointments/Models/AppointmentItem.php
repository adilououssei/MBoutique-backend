<?php

namespace App\Modules\Appointments\Models;

use App\Modules\Catalog\Models\Service;
use App\Modules\Employees\Models\Employee;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une prestation d'un rendez-vous (salon : plusieurs par rendez-vous), avec l'employé qui la réalise. */
#[Fillable(['rendez_vous_id', 'service_id', 'employe_id', 'duree_minutes', 'prix', 'ordre'])]
#[Table('prestations_rendez_vous')]
class AppointmentItem extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['prix' => 'decimal:2', 'duree_minutes' => 'integer', 'ordre' => 'integer'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id')->withTrashed();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employe_id')->withTrashed();
    }
}
