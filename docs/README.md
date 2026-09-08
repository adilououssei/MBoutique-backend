# MaBoutique — Documentation d'architecture backend

Ce dossier contient la conception technique du backend MaBoutique et son état d'implémentation. Les Phases 0 à 2 (fondations, identité/tenant, domaines/features) sont terminées et testées ; le module `Catalog` (Phase 3) est implémenté — voir `roadmap.md`.

## Index des documents

| Document | Contenu |
|---|---|
| [audit-2026-09.md](audit-2026-09.md) | **Audit architectural complet du 2026-09-06** — revue critique de toute la conception avant le début de l'implémentation |
| [authentication.md](authentication.md) | **Phase 1** — endpoints d'authentification implémentés, décisions prises, bug corrigé |
| [users.md](users.md) | **Phase 1** — modèle `User`, décision de le garder hors de `app/Modules/` |
| [stores.md](stores.md) | **Phase 1** — endpoints Business/Store/membership implémentés, 2 bugs trouvés et corrigés |
| [feature-gate.md](feature-gate.md) | **Phase 2** — BusinessDomain/Feature/FeatureGate implémentés, ordre de résolution tranché, Feature vs Permission |
| [catalog.md](catalog.md) | **Phase 3** — Category/Product/Service, contrat `Sellable`, premiers consommateurs réels des Couches 5/6 |
| [architecture.md](architecture.md) | Architecture générale, Modular Monolith, arborescence, décisions structurantes |
| [modules.md](modules.md) | Liste des modules, responsabilité de chacun, anatomie standard d'un module |
| [database.md](database.md) | Schéma conceptuel, entités principales, attributs, relations, contraintes, index |
| [multi-tenancy.md](multi-tenancy.md) | Stratégie d'isolation multi-tenant, résolution du tenant, points de fuite et parades |
| [permissions.md](permissions.md) | Rôles et permissions, spatie/laravel-permission en mode "teams", permissions par module |
| [features.md](features.md) | Domaines métiers, système de features activables, résolution des capacités d'une boutique |
| [subscriptions.md](subscriptions.md) | Plans SaaS, abonnements, limites, relation avec le système de features |
| [api-conventions.md](api-conventions.md) | Conventions REST, format des réponses/erreurs, pagination, filtres, versioning |
| [security.md](security.md) | Stratégie de sécurité (authN/Z, IDOR, rate limiting, uploads, secrets, logs) |
| [testing.md](testing.md) | Stratégie de tests, tests d'isolation multi-tenant systématiques |
| [risks.md](risks.md) | Risques architecturaux identifiés et mitigations |
| [roadmap.md](roadmap.md) | Roadmap de développement backend par phases et priorités |

## Ce qui a déjà été mis en place dans le code

**Phase 0 — fondations**
- Projet Laravel 13, API-only, Sanctum, spatie/laravel-permission en mode **teams** (`team_foreign_key = store_id`).
- `app/Shared/Tenancy/` : `TenantContext`, `StoreTeamResolver`, trait `BelongsToStore`.
- `app/Shared/Http/` : `ApiController`, `ApiResponse` (enveloppe JSON standard).
- `app/Modules/*` : squelette des 17 modules avec README.
- `laravel/boost` installé (dev).

**Phase 1 — identité et tenant (terminée, 43 tests verts)**
- Module `Users` : `UserResource` ; `User` (modèle) volontairement gardé dans `app/Models/` — voir [users.md](users.md).
- Module `Auth` : register/login/logout/me/forgot-password/reset-password/password — voir [authentication.md](authentication.md).
- Module `Tenancy` : `Business`, `BusinessUser`, `Store`, `StoreUser`, middleware `ResolveStoreContext`, policies, services transactionnels — voir [stores.md](stores.md).
- Module `Authorization` : `StoreRoleProvisioner` (rôles spatie par store), `Permissions`/`StoreRole` (constantes) — voir [permissions.md](permissions.md).
- `App\Shared\Validation\TenantScopedRules` : mécanisme de validation scopée (Couche 5), prêt pour les modules métier.
- Deux bugs critiques trouvés en implémentant et corrigés immédiatement : `StoreTeamResolver` incompatible avec l'instanciation sans conteneur de spatie, et mass assignment silencieux des clés étrangères serveur (`owner_user_id`, `business_id`) — détail dans [stores.md](stores.md).

**Phase 2 — domaines et features (terminée, 76 tests verts)**
- Module `Features` : `BusinessDomain`, `Feature`, `DomainFeature`, `FeatureDependency`, `StoreFeatureOverride` (tenant-scopé), service `FeatureGate`, middleware `feature:*`, endpoint `GET /stores/{store}/features` — voir [feature-gate.md](feature-gate.md).
- Module `Subscriptions` (version minimale) : `Plan`, `Subscription`, `SubscriptionLimits::assertCanCreateStore()` branché sur la création de boutique.
- `Store.business_domain_id` (colonne réelle, `NOT NULL`), domaines/features seedés (`FeatureSeeder`, 11 domaines / 13 features).
- Un bug mineur trouvé et corrigé : `is_active` absent de `$fillable` sur `BusinessDomain`/`Feature`.

**Phase 3 — Catalog (terminée, 108 tests verts)**
- Module `Catalog` : `Category` (partagée Product/Service), `Product`, `Service`, contrat `Sellable` (`app/Shared/Contracts/`), morph map `product`/`service` — voir [catalog.md](catalog.md).
- Premiers consommateurs réels de `TenantScopedRules::existsInCurrentStore()` (Phase 1) et des scoped route bindings `Route::scopeBindings()` (Couche 6 de l'audit), jusque-là construits/documentés sans endpoint pour les exercer.
- Permissions `categories.*`/`products.*`/`services.*` ajoutées aux rôles templates existants.
- Un bug critique trouvé et corrigé : `Relation::enforceMorphMap()` (strict) cassait le morph `tokenable` de Sanctum — remplacé par `morphMap()` (non strict).

`Customers` (prévu à l'origine dans la même phase de roadmap) n'a volontairement pas été traité — le périmètre demandé était Catalog seul.

Aucun autre module métier (Sales, Inventory, ...) n'est implémenté — c'est l'objet des phases suivantes de `roadmap.md`.
