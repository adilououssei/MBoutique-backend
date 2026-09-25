<?php

namespace App\Modules\Customers\Models;

use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\Customers\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Optional store directory entry — a Sale never requires one (see
 * docs/customers.md). Kept deliberately small: identity, contact,
 * notes. No credit/loyalty/discount fields — those are a future
 * decision, not anticipated here.
 */
#[Fillable(['name', 'phone', 'email', 'company_name', 'address', 'notes', 'is_active'])]
class Customer extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
