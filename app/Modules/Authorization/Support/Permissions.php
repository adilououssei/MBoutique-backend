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

    // Crédit client : vendre à crédit et encaisser les remboursements —
    // docs/customers.md §12. Le caissier en a besoin au comptoir.
    public const CREDITS_MANAGE = 'credits.gerer';

    // Suppliers — voir docs/modules.md §Suppliers. Les achats sont séparés des
    // fournisseurs : enregistrer un achat touche au stock et à la caisse.
    public const SUPPLIERS_VIEW = 'fournisseurs.voir';

    public const SUPPLIERS_CREATE = 'fournisseurs.creer';

    public const SUPPLIERS_UPDATE = 'fournisseurs.modifier';

    public const SUPPLIERS_DELETE = 'fournisseurs.supprimer';

    public const PURCHASES_VIEW = 'achats.voir';

    // Créer un achat et enregistrer ses règlements.
    public const PURCHASES_CREATE = 'achats.creer';

    // Employees — voir docs/modules.md §Employees. Salaires confidentiels :
    // ni le caissier ni l'employé n'y ont accès.
    public const EMPLOYEES_VIEW = 'employes.voir';

    // Créer/modifier les fiches et enregistrer les paiements (salaire, avance, prime).
    public const EMPLOYEES_MANAGE = 'employes.gerer';

    // Appointments — docs/modules.md §Appointments. Le caissier (souvent à
    // l'accueil d'un salon) prend et déplace les rendez-vous ; l'employé
    // consulte le planning.
    public const APPOINTMENTS_VIEW = 'rendez_vous.voir';

    public const APPOINTMENTS_CREATE = 'rendez_vous.creer';

    // Déplacer, confirmer, terminer, marquer absent.
    public const APPOINTMENTS_UPDATE = 'rendez_vous.modifier';

    public const APPOINTMENTS_CANCEL = 'rendez_vous.annuler';

    // Orders — docs/modules.md §Orders. Serveurs et cuisine (rôle employé)
    // prennent et font avancer les commandes ; encaisser exige en plus ventes.creer.
    public const ORDERS_VIEW = 'commandes.voir';

    public const ORDERS_CREATE = 'commandes.creer';

    // Ajouter/retirer des lignes, faire avancer le statut.
    public const ORDERS_UPDATE = 'commandes.modifier';

    public const ORDERS_CANCEL = 'commandes.annuler';

    // Créer/renommer/désactiver les tables de la salle.
    public const TABLES_MANAGE = 'tables.gerer';

    // Transformations — docs/modules.md §Transformations. Le boucher (rôle
    // employé) découpe et prépare ; annuler une opération (stock remis en
    // l'état) et définir les fiches de préparation restent au gérant.
    public const TRANSFORMATIONS_VIEW = 'transformations.voir';

    public const TRANSFORMATIONS_CREATE = 'transformations.creer';

    public const TRANSFORMATIONS_CANCEL = 'transformations.annuler';

    public const PREPARATION_SHEETS_MANAGE = 'fiches_preparation.gerer';

    // Expenses — docs/modules.md §Expenses. Le caissier paie les petites
    // dépenses (glace, transport) depuis sa caisse ; annuler reste au gérant.
    public const EXPENSES_VIEW = 'depenses.voir';

    public const EXPENSES_CREATE = 'depenses.creer';

    public const EXPENSES_CANCEL = 'depenses.annuler';

    // Equipment — docs/modules.md §Equipment. Tout le personnel voit les
    // équipements et peut signaler une panne ; le gérant les gère.
    public const EQUIPMENT_VIEW = 'equipements.voir';

    public const EQUIPMENT_MANAGE = 'equipements.gerer';

    // Warranties — docs/modules.md §Warranties. Tout le personnel consulte ;
    // le caissier (comptoir) et l'encadrement enregistrent les réclamations.
    public const WARRANTIES_VIEW = 'garanties.voir';

    public const WARRANTIES_MANAGE = 'garanties.gerer';

    // Repairs — docs/modules.md §Repairs. Accueil, caissier et techniciens
    // (rôle employé) enregistrent et font avancer les réparations.
    public const REPAIRS_VIEW = 'reparations.voir';

    public const REPAIRS_MANAGE = 'reparations.gerer';

    /** Pharmacie : consulter les ordonnances (données de santé) et les délivrer. */
    public const PRESCRIPTIONS_VIEW = 'ordonnances.voir';

    /** Pharmacie : enregistrer, corriger, clore une ordonnance. */
    public const PRESCRIPTIONS_MANAGE = 'ordonnances.gerer';

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
            self::CREDITS_MANAGE,
            self::SUPPLIERS_VIEW,
            self::SUPPLIERS_CREATE,
            self::SUPPLIERS_UPDATE,
            self::SUPPLIERS_DELETE,
            self::PURCHASES_VIEW,
            self::PURCHASES_CREATE,
            self::EMPLOYEES_VIEW,
            self::EMPLOYEES_MANAGE,
            self::APPOINTMENTS_VIEW,
            self::APPOINTMENTS_CREATE,
            self::APPOINTMENTS_UPDATE,
            self::APPOINTMENTS_CANCEL,
            self::ORDERS_VIEW,
            self::ORDERS_CREATE,
            self::ORDERS_UPDATE,
            self::ORDERS_CANCEL,
            self::TABLES_MANAGE,
            self::TRANSFORMATIONS_VIEW,
            self::TRANSFORMATIONS_CREATE,
            self::TRANSFORMATIONS_CANCEL,
            self::PREPARATION_SHEETS_MANAGE,
            self::EXPENSES_VIEW,
            self::EXPENSES_CREATE,
            self::EXPENSES_CANCEL,
            self::EQUIPMENT_VIEW,
            self::EQUIPMENT_MANAGE,
            self::WARRANTIES_VIEW,
            self::WARRANTIES_MANAGE,
            self::REPAIRS_VIEW,
            self::REPAIRS_MANAGE,
            self::PRESCRIPTIONS_VIEW,
            self::PRESCRIPTIONS_MANAGE,
            self::REPORTS_VIEW,
        ];
    }
}
