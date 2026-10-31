<?php

namespace App\Modules\Appointments\Models;

use App\Modules\Customers\Models\Customer;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Forfait vendu à un client : séances restantes, expiration. `statut`
 * stocké : actif, epuise, annule ; « expiré » se déduit de expire_le.
 */
#[Fillable(['forfait_id', 'client_id', 'vente_id', 'prix', 'seances_total', 'seances_utilisees', 'achete_le', 'expire_le', 'statut'])]
#[Table('forfaits_clients')]
class CustomerPackage extends Model
{
    use BelongsToStore;

    protected $attributes = ['statut' => 'actif', 'seances_utilisees' => 0];

    protected function casts(): array
    {
        return ['prix' => 'decimal:2', 'seances_total' => 'integer', 'seances_utilisees' => 'integer', 'achete_le' => 'datetime', 'expire_le' => 'date'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'forfait_id')->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'client_id')->withTrashed();
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(PackageSession::class, 'forfait_client_id');
    }

    public function remaining(): int
    {
        return max(0, $this->seances_total - $this->seances_utilisees);
    }

    public function isExpired(): bool
    {
        return $this->expire_le !== null && $this->expire_le->lt(today());
    }

    /** actif, epuise, expire ou annule. */
    public function displayStatus(): string
    {
        if ($this->statut === 'actif' && $this->isExpired()) {
            return 'expire';
        }

        return $this->statut;
    }

    public function isUsable(): bool
    {
        return $this->displayStatus() === 'actif' && $this->remaining() > 0;
    }
}
