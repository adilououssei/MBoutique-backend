<?php

namespace App\Modules\Authorization\Support;

use App\Modules\Tenancy\Enums\BusinessUserRole;

/**
 * Store-level role templates (docs/permissions.md §3). These are spatie
 * role NAMES, not a separate concept — a store's "Owner" role is a real
 * spatie Role row scoped to that store's team_id, freely editable by the
 * store afterwards. Nothing in the codebase branches on these values
 * (`if ($role === 'gerant')`) — they only seed a sensible starting point.
 */
final class StoreRole
{
    public const OWNER = 'proprietaire';

    public const ADMIN = 'administrateur';

    public const MANAGER = 'gerant';

    public const CASHIER = 'caissier';

    public const EMPLOYEE = 'employe';

    public static function all(): array
    {
        return [self::OWNER, self::ADMIN, self::MANAGER, self::CASHIER, self::EMPLOYEE];
    }

    /**
     * Default permissions granted to each template role for the
     * permissions that exist at this phase. Future modules extend this
     * mapping (or provision their own roles) as they add permissions.
     */
    public static function defaultPermissions(): array
    {
        $catalogFullAccess = [
            Permissions::CATEGORIES_VIEW, Permissions::CATEGORIES_CREATE, Permissions::CATEGORIES_UPDATE, Permissions::CATEGORIES_DELETE,
            Permissions::PRODUCTS_VIEW, Permissions::PRODUCTS_CREATE, Permissions::PRODUCTS_UPDATE, Permissions::PRODUCTS_DELETE, Permissions::PRODUCTS_IMPORT,
            Permissions::SERVICES_VIEW, Permissions::SERVICES_CREATE, Permissions::SERVICES_UPDATE, Permissions::SERVICES_DELETE,
        ];

        $catalogReadOnly = [
            Permissions::CATEGORIES_VIEW, Permissions::PRODUCTS_VIEW, Permissions::SERVICES_VIEW,
        ];

        // "Caissier: ... lecture seule sur produits/clients" — docs/permissions.md
        // §3 already settles customers at read-only for cashier, same as catalog.
        $customersFullAccess = [
            Permissions::CUSTOMERS_VIEW, Permissions::CUSTOMERS_CREATE, Permissions::CUSTOMERS_UPDATE, Permissions::CUSTOMERS_DELETE,
        ];

        $customersReadOnly = [
            Permissions::CUSTOMERS_VIEW,
        ];

        // "Manager: gestion quotidienne (produits, stock, ventes, ...)"
        // — docs/permissions.md §3 names "stock" explicitly, so manager
        // gets full inventory access, same tier as catalog/customers.
        $inventoryFullAccess = [
            Permissions::INVENTORY_VIEW, Permissions::INVENTORY_ADJUST, Permissions::INVENTORY_STOCKTAKE,
        ];

        $inventoryReadOnly = [
            Permissions::INVENTORY_VIEW,
        ];

        $cashRegisterFullAccess = [
            Permissions::CASH_REGISTER_VIEW, Permissions::CASH_REGISTER_MANAGE, Permissions::CASH_REGISTER_OPEN,
            Permissions::CASH_REGISTER_CLOSE, Permissions::CASH_REGISTER_ADJUST,
        ];

        // "Caissier : ventes.creer, ventes.voir, caisse.*, ..."
        // — docs/permissions.md §3, written before MANAGE (till CRUD)
        // existed as a distinct permission. Read literally today,
        // MANAGE is a setup task (defining the physical registers a
        // store owns), not a daily cashier operation — a cashier keeps
        // every *operational* permission the note names (view/open/
        // close/adjust), not the till-configuration one it never
        // anticipated. See docs/cash-register.md §"Permissions".
        $cashRegisterCashierAccess = [
            Permissions::CASH_REGISTER_VIEW, Permissions::CASH_REGISTER_OPEN,
            Permissions::CASH_REGISTER_CLOSE, Permissions::CASH_REGISTER_ADJUST,
        ];

        $cashRegisterReadOnly = [
            Permissions::CASH_REGISTER_VIEW,
        ];

        $salesFullAccess = [
            Permissions::SALES_VIEW, Permissions::SALES_CREATE,
        ];

        $salesReadOnly = [
            Permissions::SALES_VIEW,
        ];

        $suppliersFullAccess = [
            Permissions::SUPPLIERS_VIEW, Permissions::SUPPLIERS_CREATE, Permissions::SUPPLIERS_UPDATE, Permissions::SUPPLIERS_DELETE,
            Permissions::PURCHASES_VIEW, Permissions::PURCHASES_CREATE,
        ];

        return [
            self::OWNER => [Permissions::STORE_USERS_VIEW, Permissions::STORE_USERS_MANAGE, ...$catalogFullAccess, ...$customersFullAccess, ...$inventoryFullAccess, ...$cashRegisterFullAccess, ...$salesFullAccess, Permissions::SALES_CANCEL, ...$suppliersFullAccess, Permissions::REPORTS_VIEW],
            self::ADMIN => [Permissions::STORE_USERS_VIEW, Permissions::STORE_USERS_MANAGE, ...$catalogFullAccess, ...$customersFullAccess, ...$inventoryFullAccess, ...$cashRegisterFullAccess, ...$salesFullAccess, Permissions::SALES_CANCEL, ...$suppliersFullAccess, Permissions::REPORTS_VIEW],
            self::MANAGER => [Permissions::STORE_USERS_VIEW, ...$catalogFullAccess, ...$customersFullAccess, ...$inventoryFullAccess, ...$cashRegisterFullAccess, ...$salesFullAccess, Permissions::SALES_CANCEL, ...$suppliersFullAccess, Permissions::REPORTS_VIEW],
            // Cashier: read-only everywhere else, but operates the
            // register fully (opens/closes their own shift, cash in/out)
            // and processes sales — "Caissier : ventes.creer, ventes.voir,
            // caisse.*, ..." (docs/permissions.md §3, confirmed
            // literally for sales, unlike caisse.gerer — see above).
            self::CASHIER => [Permissions::STORE_USERS_VIEW, ...$catalogReadOnly, ...$customersReadOnly, ...$inventoryReadOnly, ...$cashRegisterCashierAccess, ...$salesFullAccess, Permissions::SUPPLIERS_VIEW],
            self::EMPLOYEE => [Permissions::STORE_USERS_VIEW, ...$catalogReadOnly, ...$customersReadOnly, ...$inventoryReadOnly, ...$cashRegisterReadOnly, ...$salesReadOnly],
        ];
    }

    /**
     * Maps a business-level role (BusinessUserRole) to the store-level
     * role a BusinessUser is granted on every store auto-provisioned
     * under their Business. See docs/database.md §2.
     */
    public static function forBusinessRole(string $businessRole): string
    {
        return match ($businessRole) {
            BusinessUserRole::Owner->value => self::OWNER,
            BusinessUserRole::Admin->value => self::ADMIN,
            default => self::EMPLOYEE,
        };
    }
}
