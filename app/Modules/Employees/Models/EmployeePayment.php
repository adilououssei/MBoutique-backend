<?php

namespace App\Modules\Employees\Models;

use App\Models\User;
use App\Modules\Employees\Enums\EmployeePaymentMode;
use App\Modules\Employees\Enums\EmployeePaymentType;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employe_id', 'type', 'montant', 'periode', 'mode', 'session_caisse_id', 'note', 'paye_le', 'cree_par_id'])]
#[Table('paiements_employe')]
class EmployeePayment extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return [
            'type' => EmployeePaymentType::class,
            'mode' => EmployeePaymentMode::class,
            'montant' => 'decimal:2',
            'paye_le' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employe_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
