<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Employees\Models\Employee;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['reference', 'type', 'statut', 'table_id', 'client_id', 'nom_client', 'telephone_client', 'adresse_livraison', 'date_promise', 'note', 'vente_id', 'motif_annulation', 'montant_acompte', 'cree_par_id', 'serveur_id', 'nombre_couverts', 'addition_demandee_le', 'responsable_id', 'statut_livraison', 'livreur_id', 'livree_le', 'remise_le', 'remise_par_id', 'consommation_faite'])]
#[Table('commandes')]
class Order extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return [
            'type' => OrderType::class,
            'statut' => OrderStatus::class,
            'date_promise' => 'datetime',
            'montant_acompte' => 'decimal:2',
            'nombre_couverts' => 'integer',
            'addition_demandee_le' => 'datetime',
            'livree_le' => 'datetime',
            'remise_le' => 'datetime',
            'consommation_faite' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'table_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'client_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'serveur_id');
    }

    /** Lignes encore à régler (une addition divisée en a déjà réglé une partie). */
    public function unpaidItems()
    {
        return $this->items->whereNull('vente_id');
    }

    /** Pressing : pièces déposées. */
    public function articles(): HasMany
    {
        return $this->hasMany(DropOffItem::class, 'commande_id')->orderBy('id');
    }

    public function handovers(): HasMany
    {
        return $this->hasMany(OrderHandover::class, 'commande_id')->orderBy('remis_le');
    }

    public function history(): HasMany
    {
        return $this->hasMany(OrderHistory::class, 'commande_id')->orderByDesc('created_at')->orderByDesc('id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'responsable_id')->withTrashed();
    }

    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'livreur_id')->withTrashed();
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'vente_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'commande_id');
    }

    /** Total indicatif (prix au moment de la commande). */
    public function total(): string
    {
        return $this->items->reduce(fn (string $carry, OrderItem $item) => bcadd($carry, $item->lineTotal(), 2), '0.00');
    }

    /** Montant des lignes pas encore réglées par une addition séparée. */
    public function unpaidTotal(): string
    {
        return $this->unpaidItems()->reduce(fn (string $carry, OrderItem $item) => bcadd($carry, $item->lineTotal(), 2), '0.00');
    }

    /** Reste à payer à l'encaissement : total moins l'acompte déjà en caisse. */
    public function remainingAmount(): string
    {
        return bcsub($this->unpaidTotal(), (string) $this->montant_acompte, 2);
    }
}
