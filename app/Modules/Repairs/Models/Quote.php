<?php

namespace App\Modules\Repairs\Models;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Devis d'atelier (QuoteService). Expiré : calculé, jamais stocké. */
#[Fillable(['numero', 'client_id', 'nom_client', 'telephone_client', 'objet', 'description', 'valide_jusqu_au', 'statut', 'montant_remise', 'montant_total', 'note', 'reparation_id', 'cree_par_id'])]
#[Table('devis')]
class Quote extends Model
{
    use BelongsToStore;

    public const STATUSES = ['brouillon', 'envoye', 'accepte', 'refuse', 'transforme'];

    protected function casts(): array
    {
        return ['valide_jusqu_au' => 'date', 'montant_remise' => 'decimal:2', 'montant_total' => 'decimal:2'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class, 'devis_id')->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'client_id')->withTrashed();
    }

    public function repair(): BelongsTo
    {
        return $this->belongsTo(Repair::class, 'reparation_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }

    /** Statut affiché : un devis en attente dont la validité est dépassée est « expiré ». */
    public function effectiveStatus(): string
    {
        $pending = in_array($this->statut, ['brouillon', 'envoye'], true);

        return $pending && $this->valide_jusqu_au !== null && $this->valide_jusqu_au->endOfDay()->isPast() ? 'expire' : $this->statut;
    }
}
