<?php

namespace App\Modules\CashRegister\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\CashRegister\CashRegisterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The permanent till — not to be confused with CashRegisterSession, a
 * period of use of it. See docs/cash-register.md §"CashRegister ≠
 * CashRegisterSession". `session_ouverte_id` is the single source of truth
 * for "is this register currently open, and with which session" —
 * written only by CashRegisterService, under lockForUpdate().
 */
#[Fillable(['nom', 'code', 'actif'])]
#[Table('caisses')]
class CashRegister extends Model
{
    use BelongsToStore, HasFactory;

    protected $attributes = [
        'actif' => true,
    ];

    protected static function newFactory(): CashRegisterFactory
    {
        return CashRegisterFactory::new();
    }

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }

    /**
     * Seul le créateur modifie sa caisse. Une caisse sans créateur connu
     * (créée avant ce suivi) reste modifiable par qui a caisse.gerer.
     */
    public function isEditableBy(User $user): bool
    {
        return $this->cree_par_id === null ? $user->can('caisse.gerer') : $this->cree_par_id === $user->id;
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CashRegisterSession::class, 'caisse_id');
    }

    public function isOpen(): bool
    {
        return $this->session_ouverte_id !== null;
    }
}
