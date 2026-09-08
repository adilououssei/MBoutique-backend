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

    public const SERVICES_VIEW = 'services.view';

    public const SERVICES_CREATE = 'services.create';

    public const SERVICES_UPDATE = 'services.update';

    public const SERVICES_DELETE = 'services.delete';

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
            self::SERVICES_VIEW,
            self::SERVICES_CREATE,
            self::SERVICES_UPDATE,
            self::SERVICES_DELETE,
        ];
    }
}
