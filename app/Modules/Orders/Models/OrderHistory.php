<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Journal d'une commande de dépôt : statuts, remises, paiements, livraison, affectations (append-only). */
#[Fillable(['commande_id', 'article_id', 'evenement', 'de', 'vers', 'montant', 'note', 'utilisateur_id', 'created_at'])]
#[Table('historique_commandes')]
class OrderHistory extends Model
{
    use BelongsToStore;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['montant' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(DropOffItem::class, 'article_id');
    }
}
