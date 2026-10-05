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
    public const STORE_USERS_VIEW = 'membres.voir';

    public const STORE_USERS_MANAGE = 'membres.gerer';

    // Catalog (Phase 3) — see docs/catalog.md.
    public const CATEGORIES_VIEW = 'categories.voir';

    public const CATEGORIES_CREATE = 'categories.creer';

    public const CATEGORIES_UPDATE = 'categories.modifier';

    public const CATEGORIES_DELETE = 'categories.supprimer';

    public const PRODUCTS_VIEW = 'produits.voir';

    public const PRODUCTS_CREATE = 'produits.creer';

    public const PRODUCTS_UPDATE = 'produits.modifier';

    public const PRODUCTS_DELETE = 'produits.supprimer';

    public const PRODUCTS_IMPORT = 'produits.importer';

    public const SERVICES_VIEW = 'services.voir';

    public const SERVICES_CREATE = 'services.creer';

    public const SERVICES_UPDATE = 'services.modifier';

    public const SERVICES_DELETE = 'services.supprimer';

    // Customers (Phase 3.5) — see docs/customers.md.
    public const CUSTOMERS_VIEW = 'clients.voir';

    public const CUSTOMERS_CREATE = 'clients.creer';

    public const CUSTOMERS_UPDATE = 'clients.modifier';

    public const CUSTOMERS_DELETE = 'clients.supprimer';

    // Inventory (Phase 4.1) — see docs/inventory.md.
    public const INVENTORY_VIEW = 'stock.voir';

    // Every manually-recordable movement except stocktake — see
    // StockMovementPolicy and docs/inventory.md §"Permissions".
    public const INVENTORY_ADJUST = 'stock.ajuster';

    public const INVENTORY_STOCKTAKE = 'stock.inventorier';

    // CashRegister (Phase 4.2) — see docs/cash-register.md. Names match
    // docs/permissions.md §3, which had already anticipated view/open/
    // close/adjust before this phase; MANAGE (till CRUD) is the one
    // addition, justified by section 29 of the Phase 4.2 brief — see
    // docs/cash-register.md §"Permissions".
    public const CASH_REGISTER_VIEW = 'caisse.voir';

    public const CASH_REGISTER_MANAGE = 'caisse.gerer';

    public const CASH_REGISTER_OPEN = 'caisse.ouvrir';

    public const CASH_REGISTER_CLOSE = 'caisse.fermer';

    public const CASH_REGISTER_ADJUST = 'caisse.ajuster';

    // Sales (Phase 4.3) — see docs/sales.md. Only view/create: no
    // sales.cancel, since cancellation isn't implemented this phase
    // (it would need a stock/cash reversal not built yet) — an unused
    // permission constant would just be dead weight.
    public const SALES_VIEW = 'ventes.voir';

    public const SALES_CREATE = 'ventes.creer';

    // Annulation = remise en stock + remboursement en caisse : réservée à
    // l'encadrement (pas au caissier), voir docs/sales.md §20.
    public const SALES_CANCEL = 'ventes.annuler';

    // Suppliers — voir docs/modules.md §Suppliers. Les achats sont séparés des
    // fournisseurs : enregistrer un achat touche au stock et à la caisse.
    public const SUPPLIERS_VIEW = 'fournisseurs.voir';

    public const SUPPLIERS_CREATE = 'fournisseurs.creer';

    public const SUPPLIERS_UPDATE = 'fournisseurs.modifier';

    public const SUPPLIERS_DELETE = 'fournisseurs.supprimer';

    public const PURCHASES_VIEW = 'achats.voir';

    // Créer un achat et enregistrer ses règlements.
    public const PURCHASES_CREATE = 'achats.creer';

    // Reports (Phase 6, tableau de bord) — see app/Modules/Reports/README.md. Read-only
    // aggregates over Sales; the consolidated multi-store variant
    // (rapports.voir_consolide) is business-level and not built yet.
    public const REPORTS_VIEW = 'rapports.voir';

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
            self::SALES_CANCEL,
            self::SUPPLIERS_VIEW,
            self::SUPPLIERS_CREATE,
            self::SUPPLIERS_UPDATE,
            self::SUPPLIERS_DELETE,
            self::PURCHASES_VIEW,
            self::PURCHASES_CREATE,
            self::REPORTS_VIEW,
        ];
    }
}
