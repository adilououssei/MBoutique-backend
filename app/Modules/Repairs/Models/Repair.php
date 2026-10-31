<?php

namespace App\Modules\Repairs\Models;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Employees\Models\Employee;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['numero', 'client_id', 'nom_client', 'telephone_client', 'appareil_id', 'devis_id', 'appareil', 'marque', 'modele', 'imei', 'numero_serie', 'bien', 'photos', 'panne', 'etat_reception', 'diagnostic', 'technicien_id', 'cout_estime', 'cout_final', 'montant_paye', 'montant_credit', 'statut', 'depose_le', 'prevu_le', 'livre_le', 'livre_par_id', 'remis_a', 'reception_confirmee', 'note', 'cree_par_id'])]
#[Table('reparations')]
class Repair extends Model
{
    use BelongsToStore;

    /** `attente_validation` : devis soumis au client, travaux suspendus à son accord. */
    public const STATUSES = ['recu', 'diagnostic', 'attente_validation', 'attente_pieces', 'en_reparation', 'repare', 'livre', 'annule'];

    public const OPEN = ['recu', 'diagnostic', 'attente_validation', 'attente_pieces', 'en_reparation', 'repare'];

    protected function casts(): array
    {
        return [
            'cout_estime' => 'decimal:2', 'cout_final' => 'decimal:2', 'montant_paye' => 'decimal:2', 'montant_credit' => 'decimal:2',
            'depose_le' => 'datetime', 'prevu_le' => 'date', 'livre_le' => 'datetime', 'bien' => 'array', 'photos' => 'array', 'reception_confirmee' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'client_id')->withTrashed();
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'technicien_id')->withTrashed();
    }

    public function services(): HasMany
    {
        return $this->hasMany(RepairLine::class, 'reparation_id')->orderBy('id');
    }

    /** Tous les techniciens du dossier (le principal reste `technicien_id`). */
    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'techniciens_reparation', 'reparation_id', 'employe_id')->withTimestamps();
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'devis_id');
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'livre_par_id');
    }

    /** @param  array<int, int>  $employeeIds */
    public function syncTechnicians(array $employeeIds): void
    {
        $this->technicians()->sync(collect($employeeIds)->mapWithKeys(fn (int $id) => [$id => ['boutique_id' => $this->boutique_id]])->all());
    }

    /** Montant des lignes facturées : prestations (et frais, remises) + pièces refacturées. */
    public function linesTotal(): ?string
    {
        $services = $this->relationLoaded('services') ? $this->services : $this->services()->get();
        if ($services->isEmpty()) {
            return null;
        }
        $parts = ($this->relationLoaded('parts') ? $this->parts : $this->parts()->get())->where('facturee', true);

        return bcadd(
            $services->reduce(fn (string $c, $s) => bcadd($c, (string) $s->montant, 2), '0.00'),
            $parts->reduce(fn (string $c, $p) => bcadd($c, (string) $p->montant, 2), '0.00'),
            2,
        );
    }

    public function isLate(): bool
    {
        return $this->isOpen() && $this->prevu_le !== null && $this->prevu_le->endOfDay()->isPast();
    }

    public function parts(): HasMany
    {
        return $this->hasMany(RepairPart::class, 'reparation_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(RepairPayment::class, 'reparation_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(RepairFollowUp::class, 'reparation_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->statut, self::OPEN, true);
    }

    /** Montant dû : le coût final s'il est fixé, sinon les lignes facturées, sinon l'estimation. */
    public function amountDue(): string
    {
        return (string) ($this->cout_final ?? $this->linesTotal() ?? $this->cout_estime ?? '0.00');
    }

    /** Reste à encaisser sur le dossier (hors part passée au compte du client). */
    public function remaining(): string
    {
        return bcsub(bcsub($this->amountDue(), (string) $this->montant_paye, 2), (string) $this->montant_credit, 2);
    }
}
