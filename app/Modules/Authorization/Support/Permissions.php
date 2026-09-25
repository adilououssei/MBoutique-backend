<?php

namespace App\Modules\Authorization\Support;

/**
 * Central registry of permission names for the socle built in this phase.
 * Each future module adds its own constants here (or in its own
 * equivalent file) as it is implemented — see docs/permissions.md §4.
 * Kept as plain string constants, not an enum, because spatie/laravel-permission
 * permissions are free-form strings and enum values would just be unwrapped
 * again everywhere they're passed to the package.
 */
final class Permissions
{
    public const STORE_USERS_VIEW = 'store_users.view';

    public const STORE_USERS_MANAGE = 'store_users.manage';

    // Catalog (Phase 3) — see docs/catalog.md.
    public const CATEGORIES_VIEW = 'categories.view';

    public const CATEGORIES_CREATE = 'categories.create';

    public const CATEGORIES_UPDATE = 'categories.update';

    public const CATEGORIES_DELETE = 'categories.delete';

    public const PRODUCTS_VIEW = 'products.view';

    public const PRODUCTS_CREATE = 'products.create';

    public const PRODUCTS_UPDATE = 'products.update';

    public const PRODUCTS_DELETE = 'products.delete';

    public const PRODUCTS_IMPORT = 'products.import';

    public const SERVICES_VIEW = 'services.view';

    public const SERVICES_CREATE = 'services.create';

    public const SERVICES_UPDATE = 'services.update';

    public const SERVICES_DELETE = 'services.delete';

    // Customers (Phase 3.5) — see docs/customers.md.
    public const CUSTOMERS_VIEW = 'customers.view';

    public const CUSTOMERS_CREATE = 'customers.create';

    public const CUSTOMERS_UPDATE = 'customers.update';

    public const CUSTOMERS_DELETE = 'customers.delete';

    // Inventory (Phase 4.1) — see docs/inventory.md.
    public const INVENTORY_VIEW = 'inventory.view';

    // Every manually-recordable movement except stocktake — see
    // StockMovementPolicy and docs/inventory.md §"Permissions".
    public const INVENTORY_ADJUST = 'inventory.adjust';

    public const INVENTORY_STOCKTAKE = 'inventory.stocktake';

    // CashRegister (Phase 4.2) — see docs/cash-register.md. Names match
    // docs/permissions.md §3, which had already anticipated view/open/
    // close/adjust before this phase; MANAGE (till CRUD) is the one
    // addition, justified by section 29 of the Phase 4.2 brief — see
    // docs/cash-register.md §"Permissions".
    public const CASH_REGISTER_VIEW = 'cash_register.view';

    public const CASH_REGISTER_MANAGE = 'cash_register.manage';

    public const CASH_REGISTER_OPEN = 'cash_register.open';

    public const CASH_REGISTER_CLOSE = 'cash_register.close';

    public const CASH_REGISTER_ADJUST = 'cash_register.adjust';

    // Sales (Phase 4.3) — see docs/sales.md. Only view/create: no
    // sales.cancel, since cancellation isn't implemented this phase
    // (it would need a stock/cash reversal not built yet) — an unused
    // permission constant would just be dead weight.
    public const SALES_VIEW = 'sales.view';

    public const SALES_CREATE = 'sales.create';

    public static function all(): array
    {
        return [
            self::STORE_USERS_VIEW,
            self::STORE_USERS_MANAGE,
            self::CATEGORIES_VIEW,
            self::CATEGORIES_CREATE,
            self::CATEGORIES_UPDATE,
            self::CATEGORIES_DELETE,
            self::PRODUCTS_VIEW,
            self::PRODUCTS_CREATE,
            self::PRODUCTS_UPDATE,
            self::PRODUCTS_DELETE,
            self::PRODUCTS_IMPORT,
            self::SERVICES_VIEW,
            self::SERVICES_CREATE,
            self::SERVICES_UPDATE,
            self::SERVICES_DELETE,
            self::CUSTOMERS_VIEW,
            self::CUSTOMERS_CREATE,
            self::CUSTOMERS_UPDATE,
            self::CUSTOMERS_DELETE,
            self::INVENTORY_VIEW,
            self::INVENTORY_ADJUST,
            self::INVENTORY_STOCKTAKE,
            self::CASH_REGISTER_VIEW,
            self::CASH_REGISTER_MANAGE,
            self::CASH_REGISTER_OPEN,
            self::CASH_REGISTER_CLOSE,
            self::CASH_REGISTER_ADJUST,
            self::SALES_VIEW,
            self::SALES_CREATE,
        ];
    }
}
