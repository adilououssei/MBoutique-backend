<?php

namespace App\Modules\CashRegister\Models;

use App\Models\User;
use App\Modules\CashRegister\Enums\CashRegisterSessionStatus;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\CashRegister\CashRegisterSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A period of use of a CashRegister. `expected_closing_amount` is always
 * computed by CashRegisterService from the ledger at close time — never
 * accepted from the client. See docs/cash-register.md.
 */
#[Fillable(['caisse_id', 'ouverte_par_id', 'fermee_par_id', 'ouverte_le', 'fermee_le', 'montant_ouverture', 'montant_fermeture_attendu', 'montant_fermeture_reel', 'ecart', 'statut', 'note_fermeture'])]
#[Table('sessions_caisse')]
class CashRegisterSession extends Model
{
    use BelongsToStore, HasFactory;

    protected function casts(): array
    {
        return [
            'statut' => CashRegisterSessionStatus::class,
            'ouverte_le' => 'datetime',
            'fermee_le' => 'datetime',
            'montant_ouverture' => 'decimal:2',
            'montant_fermeture_attendu' => 'decimal:2',
            'montant_fermeture_reel' => 'decimal:2',
            'ecart' => 'decimal:2',
        ];
    }

    protected static function newFactory(): CashRegisterSessionFactory
    {
        return CashRegisterSessionFactory::new();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'caisse_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ouverte_par_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fermee_par_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class, 'session_caisse_id');
    }

    public function isOpen(): bool
    {
        return $this->statut === CashRegisterSessionStatus::Open;
    }
}
