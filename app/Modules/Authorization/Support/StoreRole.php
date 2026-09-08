<?php

namespace App\Modules\Authorization\Support;

/**
 * Store-level role templates (docs/permissions.md §3). These are spatie
 * role NAMES, not a separate concept — a store's "Owner" role is a real
 * spatie Role row scoped to that store's team_id, freely editable by the
 * store afterwards. Nothing in the codebase branches on these values
 * (`if ($role === 'manager')`) — they only seed a sensible starting point.
 */
final class StoreRole
{
    public const OWNER = 'owner';

    public const ADMIN = 'admin';

    public const MANAGER = 'manager';

    public const CASHIER = 'cashier';

    public const EMPLOYEE = 'employee';

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
            Permissions::PRODUCTS_VIEW, Permissions::PRODUCTS_CREATE, Permissions::PRODUCTS_UPDATE, Permissions::PRODUCTS_DELETE,
            Permissions::SERVICES_VIEW, Permissions::SERVICES_CREATE, Permissions::SERVICES_UPDATE, Permissions::SERVICES_DELETE,
        ];

        $catalogReadOnly = [
            Permissions::CATEGORIES_VIEW, Permissions::PRODUCTS_VIEW, Permissions::SERVICES_VIEW,
        ];

        return [
            self::OWNER => [Permissions::STORE_USERS_VIEW, Permissions::STORE_USERS_MANAGE, ...$catalogFullAccess],
            self::ADMIN => [Permissions::STORE_USERS_VIEW, Permissions::STORE_USERS_MANAGE, ...$catalogFullAccess],
            // "Manager: gestion quotidienne (produits, stock, ventes, ...)"
            // — docs/permissions.md §3 already names produits explicitly.
            self::MANAGER => [Permissions::STORE_USERS_VIEW, ...$catalogFullAccess],
            self::CASHIER => [Permissions::STORE_USERS_VIEW, ...$catalogReadOnly],
            self::EMPLOYEE => [Permissions::STORE_USERS_VIEW, ...$catalogReadOnly],
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
            'owner' => self::OWNER,
            'admin' => self::ADMIN,
            default => self::EMPLOYEE,
        };
    }
}
