<?php

namespace App\Modules\Appointments\Models;

use App\Modules\Catalog\Models\Service;
use App\Modules\Employees\Models\Employee;
use App\Modules\Sales\Models\Sale;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Commission d'un employé sur une prestation encaissée. due → payee (par
 * la paie) ; annulee si la vente est annulée avant paiement. Après
 * paiement, l'annulation crée une ligne négative due (déduite ensuite).
 */
#[Fillable(['employe_id', 'vente_id', 'ligne_vente_id', 'service_id', 'base', 'mode', 'valeur', 'montant', 'statut', 'paiement_employe_id'])]
#[Table('commissions')]
class Commission extends Model
{
    use BelongsToStore;

    protected $attributes = ['statut' => 'due'];

    protected function casts(): array
    {
        return ['base' => 'decimal:2', 'valeur' => 'decimal:2', 'montant' => 'decimal:2'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employe_id')->withTrashed();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id')->withTrashed();
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'vente_id');
    }
}
