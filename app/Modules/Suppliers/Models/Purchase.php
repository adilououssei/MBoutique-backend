<?php

namespace App\Modules\Suppliers\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['fournisseur_id', 'reference', 'numero_facture_fournisseur', 'reference_commande', 'statut', 'commande_le', 'recu_par_id', 'remise_fournisseur', 'frais_annexes', 'detail_frais', 'montant_total', 'montant_paye', 'note', 'cle_idempotence', 'achete_le', 'cree_par_id'])]
#[Table('achats')]
class Purchase extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return [
            'montant_total' => 'decimal:2',
            'montant_paye' => 'decimal:2',
            'remise_fournisseur' => 'decimal:2',
            'commande_le' => 'datetime',
            'frais_annexes' => 'decimal:2',
            'achete_le' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'fournisseur_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class, 'achat_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class, 'achat_id');
    }

    /** Commande pas encore reçue : ni stock, ni dette. */
    public function isOrder(): bool
    {
        return $this->statut === 'commande';
    }

    /** Reste à payer au fournisseur (rien tant que la commande n'est pas reçue). */
    public function amountDue(): string
    {
        if ($this->isOrder()) {
            return '0.00';
        }

        return bcsub((string) $this->montant_total, (string) $this->montant_paye, 2);
    }

    /** `paye`, `partiel` ou `non_paye`. */
    public function paymentStatus(): string
    {
        if (bccomp($this->amountDue(), '0', 2) <= 0) {
            return 'paye';
        }

        return bccomp((string) $this->montant_paye, '0', 2) > 0 ? 'partiel' : 'non_paye';
    }
}
