# Architecture générale

> **Mise à jour (audit architectural du 2026-09-06)** : ce document reste valable dans ses grandes lignes ; l'audit ([audit-2026-09.md](audit-2026-09.md)) a précisé que `Tenancy` porte aussi désormais `BusinessUser` (autorisation business-level, distincte de l'appartenance à une boutique — voir [database.md](database.md) §2 et [permissions.md](permissions.md) §7), et a assoupli la règle de dépendance inter-modules pour distinguer lecture et écriture (voir [modules.md](modules.md)).
>
> **Mise à jour (Phase 2, 2026-09-07)** : le module `Features` (BusinessDomain, Feature, DomainFeature, StoreFeatureOverride, FeatureDependency, `FeatureGate`) et une version minimale de `Subscriptions` (Plan, Subscription, `SubscriptionLimits`) sont implémentés — voir [feature-gate.md](feature-gate.md). `Store` dépend maintenant de `Features` (`business_domain_id`), confirmant l'ordre de dépendance déjà documenté dans [modules.md](modules.md) (`Tenancy`/`Authorization`/`Features` dépendus par tous).
>
> **Mise à jour (Phase 3, 2026-09-08)** : premier module métier vertical, `Catalog` (Category, Product, Service, contrat `Sellable` dans `Shared/Contracts`) — voir [catalog.md](catalog.md). Confirme en conditions réelles le pipeline complet documenté depuis l'audit : `auth:sanctum → store → feature:* → Policy (store + permission) → FormRequest (validation scopée) → Resource`. Premier consommateur réel de `TenantScopedRules` et des scoped route bindings (Couche 6), jusque-là construits mais non exercés faute d'endpoint.

## 1. Vision et contraintes qui pilotent l'architecture

MaBoutique n'est pas une seule application métier mais un **SaaS multi-boutiques, multi-métiers**. Deux boutiques peuvent avoir des besoins fonctionnels presque disjoints (un coiffeur ne gère pas de stock, un supermarché ne gère pas de rendez-vous) tout en partageant un socle commun (utilisateurs, boutiques, caisse, rapports, abonnement). Trois contraintes en découlent directement :

1. **L'isolation des données entre boutiques est non négociable** — une fuite entre tenants est le pire scénario possible pour ce produit. Voir [multi-tenancy.md](multi-tenancy.md).
2. **Le comportement doit varier par métier sans branches `if ($store->type === ...)` dispersées dans le code** — d'où le système Domain → Feature. Voir [features.md](features.md).
3. **Le produit doit pouvoir grandir (nouveaux métiers, nouveaux modules) sans réécriture** — d'où le choix d'un Modular Monolith plutôt que des microservices.

## 2. Modular Monolith plutôt que microservices

| Critère | Microservices | Modular Monolith (retenu) |
|---|---|---|
| Complexité opérationnelle | Élevée (déploiement, réseau, observabilité distribuée) dès le jour 1 | Un seul déploiement, une seule base de code |
| Transactions cross-domaine (ex: une vente touche Sales + Inventory + CashRegister) | Nécessite sagas / eventual consistency | Transactions SQL classiques |
| Vitesse de développement en phase de découverte produit | Ralentie par les frontières figées trop tôt | Rapide, les frontières peuvent bouger |
| Équipe actuelle | Suppose plusieurs équipes autonomes | Une équipe, un backend |
| Chemin d'évolution | — | Les modules bien découplés peuvent être extraits en services plus tard si le besoin apparaît réellement |

**Décision : Modular Monolith.** Le produit est encore en phase de définition de son périmètre fonctionnel exact par métier ; découper en microservices maintenant figerait des frontières qu'on ne connaît pas encore avec certitude, pour un coût opérationnel que l'équipe n'a pas besoin de payer aujourd'hui. La discipline modulaire (frontières de code claires, pas d'accès direct aux tables d'un autre module) préserve la possibilité d'extraire un module plus tard.

## 3. Arborescence retenue

```
app/
├── Modules/
│   ├── Auth/              # mécanique d'authentification (Sanctum)
│   ├── Users/              # profil utilisateur plateforme
│   ├── Tenancy/            # Business, Store, StoreUser, résolution du tenant
│   ├── Authorization/      # Role, Permission (spatie, scopé par store)
│   ├── Features/           # BusinessDomain, Feature, activation par store
│   ├── Catalog/            # Product, Service (voir database.md §Sellable)
│   ├── Inventory/          # Stock, StockMovement
│   ├── Sales/              # Sale, SaleItem, paiement, remboursement
│   ├── CashRegister/       # session de caisse, mouvements de caisse
│   ├── Customers/
│   ├── Suppliers/
│   ├── Employees/
│   ├── Appointments/
│   ├── Orders/             # commandes en cours -> se transforment en Sale
│   ├── Reports/
│   ├── Subscriptions/      # Plan, Subscription, limites
│   └── Notifications/
│
└── Shared/
    ├── Tenancy/            # contrats + implémentation transverses (déjà en place)
    ├── Http/               # ApiController, ApiResponse, middlewares génériques
    ├── Support/            # filtres de requête, pagination, helpers
    ├── Contracts/          # interfaces partagées entre modules (ex: Sellable)
    └── Exceptions/
```

### Écarts assumés par rapport à la proposition initiale

La structure de départ proposait `Businesses/`, `Stores/` et `Tenancy/` comme trois modules séparés, et `Domains/` séparé de `Features/`. Après analyse, ces paires sont fusionnées :

- **`Tenancy` regroupe Business, Store et StoreUser.** Ces trois entités forment un seul agrégat cohérent (« qui possède quoi, qui en est membre ») ; les séparer en trois modules aurait créé des dépendances circulaires triviales (Store dépend de Business, StoreUser dépend des deux) sans bénéfice de découplage réel.
- **`Features` regroupe BusinessDomain et Feature.** Un domaine métier n'a de sens que par les features qu'il active ; ce sont les deux faces d'un même mécanisme de feature-flagging. Voir [features.md](features.md).
- **`Catalog` regroupe Product et Service** au lieu de deux modules distincts, via un contrat commun `Sellable`. Voir [database.md](database.md) §10.
- **`Authorization` est extrait de `Tenancy`** (au lieu d'y être implicite) car il encapsule spatie/laravel-permission et sera réutilisé par tous les modules qui protègent des actions par permission — il mérite sa propre frontière.

Chaque module garde, en interne, l'anatomie standard décrite dans [modules.md](modules.md).

## 4. Pourquoi `Shared/` et pas un module de plus

`Shared/` ne contient **aucune règle métier** — uniquement des contrats et primitives que tout module utilise (résolution du tenant courant, enveloppe de réponse API, exceptions). Le mélanger avec les modules romprait la règle « un module = un domaine métier » et inviterait à y déverser du code métier « parce que c'est déjà partagé ». La règle de dépendance est stricte : les modules dépendent de `Shared`, jamais l'inverse, et un module ne dépend jamais directement des classes internes (`Models`, `Http\Controllers`) d'un autre module — uniquement de ses éventuels `Contracts` exposés (voir `Sellable` par exemple, utilisé par `Sales` et `Inventory` sans qu'ils connaissent `Catalog`).

## 5. Flux d'une requête type

```
Requête HTTP  ->  routes/api.php (préfixe /v1)
             ->  middleware `auth:sanctum` (Auth module)
             ->  middleware `store` (Tenancy: résout {store}, vérifie l'appartenance, alimente TenantContext)
             ->  middleware `feature:products` (Features: la boutique a-t-elle cette capacité ?)
             ->  middleware `permission:products.view` (Authorization, scopé au store courant via team_id)
             ->  Controller du module (extends Shared\Http\Controllers\ApiController)
             ->  FormRequest (validation)
             ->  Policy (autorisation fine sur l'instance, ex: "cette vente appartient-elle à ce store")
             ->  Service métier du module
             ->  Model (scope global `store` appliqué automatiquement via BelongsToStore)
             ->  Resource (transformation JSON)
             ->  ApiResponse::success() (enveloppe standard)
```

Ce pipeline est décrit en détail dans [multi-tenancy.md](multi-tenancy.md) (résolution du tenant) et [api-conventions.md](api-conventions.md) (format de sortie).

## 6. Décisions techniques déjà actées

| Décision | Choix | Raison |
|---|---|---|
| Framework | Laravel 13, API-only (pas de `web` applicatif) | Le frontend est un client React/mobile séparé |
| Authentification | Laravel Sanctum (tokens personnels) | Standard Laravel pour SPA/mobile, pas besoin d'OAuth complet |
| RBAC | spatie/laravel-permission, mode `teams` avec `team_foreign_key = store_id` | Rôles/permissions nativement scopés par boutique sans réinventer une table pivot ; voir [permissions.md](permissions.md) pour les alternatives évaluées |
| Isolation tenant | Colonne `store_id` + Global Scope Eloquent (`BelongsToStore`) + middleware de résolution, jamais de base de données séparée par tenant | Voir [multi-tenancy.md](multi-tenancy.md) §"Options évaluées" |
| Base de données | MySQL unique, partagée entre tenants (shared database, shared schema) | Cohérent avec le volume attendu (PME), simplifie les migrations et les rapports cross-store pour un même Business |

## 7. Ce que cette architecture NE couvre pas encore

Volontairement hors périmètre de cette étape (voir [roadmap.md](roadmap.md)) : implémentation des controllers/models métier, paiement des abonnements, notifications push réelles, multi-devise, multi-langue. Ces sujets sont mentionnés dans les documents correspondants comme points d'attention futurs, pas comme du code à livrer maintenant.
