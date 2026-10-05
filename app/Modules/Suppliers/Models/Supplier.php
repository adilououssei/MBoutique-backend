<?php

namespace App\Modules\Suppliers\Models;

use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\Suppliers\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['nom', 'nom_contact', 'telephone', 'email', 'adresse', 'notes', 'actif'])]
#[Table('fournisseurs')]
class Supplier extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }

    protected static function newFactory(): SupplierFactory
    {
        return SupplierFactory::new();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'fournisseur_id');
    }
}
