<?php

namespace App\Modules\Tenancy\Enums;

/**
 * Business-level authorization only (creating stores, managing billing).
 * Deliberately NOT related to spatie/laravel-permission roles, which are
 * store-level. See docs/permissions.md §7.
 */
enum BusinessUserRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
}
