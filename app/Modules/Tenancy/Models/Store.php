<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Employees\Models\Employee;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\Sale;
use App\Modules\Suppliers\Models\Purchase;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Enums\StoreStatus;
use Database\Factories\Modules\Tenancy\StoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The operational tenant: the isolation boundary for every business-data
 * table (BelongsToStore). Belongs to one Business, which may own several,
 * and to exactly one BusinessDomain (added in Phase 2 — see
 * docs/features.md and the migration that adds domaine_activite_id).
 *
 * The hasMany relations below are also what scoped route binding uses
 * (`{product}` under `{store}` resolves through products()), which is why
 * route parameter names stay English even though URL segments are French.
 */
#[Fillable(['nom', 'slug', 'adresse', 'telephone', 'devise', 'fuseau_horaire', 'domaine_activite_id'])]
#[Table('boutiques')]
class Store extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * See the identical note on App\Models\User — Eloquent's create()
     * doesn't re-fetch DB-level defaults, so this must mirror the
     * `boutiques` migration's defaults.
     */
    protected $attributes = [
        'statut' => 'active',
        'devise' => 'XOF',
        'fuseau_horaire' => 'UTC',
    ];

    protected static function newFactory(): StoreFactory
    {
        return StoreFactory::new();
    }

    protected function casts(): array
    {
        return [
            'statut' => StoreStatus::class,
            'parametres' => AsArrayObject::class,
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'entreprise_id');
    }

    public function businessDomain(): BelongsTo
    {
        return $this->belongsTo(BusinessDomain::class, 'domaine_activite_id');
    }

    public function storeUsers(): HasMany
    {
        return $this->hasMany(StoreUser::class, 'boutique_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'boutique_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'boutique_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'boutique_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'boutique_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'boutique_id');
    }

    public function diningTables(): HasMany
    {
        return $this->hasMany(DiningTable::class, 'boutique_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'boutique_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'boutique_id');
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class, 'boutique_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'boutique_id');
    }

    public function cashRegisters(): HasMany
    {
        return $this->hasMany(CashRegister::class, 'boutique_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'boutique_id');
    }
}
