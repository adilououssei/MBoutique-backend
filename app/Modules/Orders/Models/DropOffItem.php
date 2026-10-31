<?php

namespace App\Modules\Orders\Models;

use App\Modules\Employees\Models\Employee;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pièce déposée au pressing : code unique, description, état constaté,
 * statut propre (reçu → en traitement → contrôle → prêt → remis), employé.
 */
#[Fillable(['commande_id', 'ligne_commande_id', 'code', 'type_article', 'couleur', 'marque', 'etat', 'defauts', 'instructions', 'photo', 'statut', 'employe_id', 'remise_id', 'remis_le'])]
#[Table('articles_depot')]
class DropOffItem extends Model
{
    use BelongsToStore;

    public const STATUSES = ['recu', 'en_traitement', 'controle', 'pret', 'remis', 'annule'];

    protected $attributes = ['statut' => 'recu'];

    protected function casts(): array
    {
        return ['remis_le' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'commande_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'ligne_commande_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employe_id')->withTrashed();
    }

    public function isOpen(): bool
    {
        return ! in_array($this->statut, ['remis', 'annule'], true);
    }
}
