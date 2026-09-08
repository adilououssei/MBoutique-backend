# Roadmap de développement backend

> **Mise à jour (audit architectural du 2026-09-06)** : Phase 0 complétée par la correction `BelongsToStore` et l'installation de Laravel Boost (voir [audit-2026-09.md](audit-2026-09.md)). Phase 1 enrichie de `BusinessUser` et des scoped bindings. Phase 5 enrichie de l'entité `Table`.

Ordre pensé pour que chaque phase soit testable de bout en bout avant d'attaquer la suivante, et pour que le mécanisme le plus critique (isolation multi-tenant) soit validé sur un cas réel le plus tôt possible.

## Phase 0 — Fondations (fait dans cette étape)
- Installation Laravel + Sanctum + spatie/laravel-permission (mode teams) + Laravel Boost.
- Socle `Shared/Tenancy` (`TenantContext`, `BelongsToStore`, `StoreTeamResolver`) — `BelongsToStore` corrigé lors de l'audit (store_id forcé et immuable).
- Socle `Shared/Http` (`ApiController`, `ApiResponse`) et conventions d'erreur globales.
- Squelette des 17 modules (dossiers + responsabilités documentées).
- Documentation complète (`/docs`), revue et corrigée par un audit architectural complet.

## Phase 1 — Identité et tenant (bloquant pour tout le reste)
1. Module `Users` : modèle `User`, profil.
2. Module `Auth` : inscription, login, logout, Sanctum, `/me`.
3. Module `Tenancy` : `Business`, `Store`, `StoreUser`, **`BusinessUser`** (ajouté à l'audit — voir [database.md](database.md) §2 et [permissions.md](permissions.md) §7), middleware `ResolveStoreContext`, endpoints `/me/stores`, création de boutique (avec auto-provisioning des `StoreUser` pour les `BusinessUser` du Business).
4. Module `Authorization` : intégration spatie effective, rôles modèles, endpoints de gestion des rôles/permissions d'un store.
5. Appliquer dès cette phase les scoped route bindings sur toutes les routes `stores.{ressource}` ([multi-tenancy.md](multi-tenancy.md) Couche 6) et la validation scopée sur tout champ `*_id` ([multi-tenancy.md](multi-tenancy.md) Couche 5) — pas seulement documentées, réellement codées dès le premier `FormRequest`.
6. **Écrire la suite de tests d'isolation multi-tenant ([testing.md](testing.md) §2 et §2 bis) sur ce module en premier**, avant de la répliquer ailleurs.

Critère de sortie de phase : un utilisateur peut s'inscrire, créer une boutique, inviter un second utilisateur avec un rôle, et il est prouvé par test qu'un troisième utilisateur non-membre ne peut rien lire de cette boutique — accès direct **et** injection de référence.

> **✅ Phase 1 terminée (2026-09-07)** — voir `docs/authentication.md`, `docs/users.md`, `docs/stores.md` pour le détail de ce qui a été livré, et `docs/audit-2026-09.md`-style constats consignés dans ces mêmes documents pour les deux bugs trouvés et corrigés en cours d'implémentation (résolveur d'équipe spatie sans injection de constructeur possible ; mass assignment des clés étrangères serveur). 43 tests, tous verts. Non livré volontairement dans cette phase : flux d'invitation par e-mail (StoreUser reste en `status: active` immédiat, pas `invited` avec étape d'acceptation — nécessite le module `Notifications`), vérification d'e-mail, `business_domain_id` sur `stores` (Phase 2).

## Phase 2 — Domaines et features
6. Module `Features` : `BusinessDomain`, `Feature`, `DomainFeature`, `StoreFeatureOverride`, service `FeatureGate`, middleware `feature:*`, endpoint `/stores/{store}/features`.
7. Module `Subscriptions` (version minimale, sans paiement) : `Plan`, `Subscription`, service `SubscriptionLimits`.
8. Seed des domaines/features de départ (alimentation générale, coiffure, restaurant, pharmacie, autre).

Critère de sortie : créer une boutique avec un domaine donné active automatiquement le bon jeu de features, vérifiable via l'endpoint dédié.

> **✅ Phase 2 terminée (2026-09-07)** — voir [feature-gate.md](feature-gate.md) pour le détail complet (ordre de résolution tranché, exemples vérifiés, décisions "fail-open"). 11 domaines et 13 features seedés (`FeatureSeeder`), `SubscriptionLimits::assertCanCreateStore()` branché sur la création de boutique. 76 tests au total, tous verts — aucune régression Phase 1 (les tests existants ont dû être adaptés pour fournir un `business_domain_id`, désormais obligatoire). Un bug mineur trouvé et corrigé : `is_active` absent de `$fillable` sur `BusinessDomain`/`Feature`. Non livré volontairement : API d'administration des domaines/features (reste Phase 6, `platform_admin`), paiement/facturation, `max_users_per_store`/`max_products_per_store` (posés au schéma, sans point d'application tant qu'Employees/Products n'existent pas).

## Phase 3 — Premier module métier vertical complet (valide le pipeline de bout en bout)
9. Module `Catalog` (Category + Product + Service + contrat `Sellable`).
10. Module `Customers`.

Choix volontaire de commencer par un module simple et transverse à tous les métiers pour valider le pipeline complet (middleware store → feature → permission → policy → resource) sur un cas peu risqué avant d'attaquer les modules transactionnels.

> **✅ Catalog terminé (2026-09-08)** — voir [catalog.md](catalog.md). `Customers` explicitement **non traité** cette phase (le périmètre demandé était Catalog seul) — reste à faire avant de considérer la Phase 3 complète. 108 tests au total, tous verts, 0 régression. Un bug critique trouvé et corrigé : `Relation::enforceMorphMap()` (variante stricte) cassait le morph `tokenable` de Sanctum au login — remplacé par `morphMap()` (non strict).

## Phase 4 — Cœur transactionnel
11. Module `Inventory` (StockMovement, solde dénormalisé, verrouillage `lockForUpdate` — voir [database.md](database.md) §11).
12. Module `CashRegister` (session, mouvements).
13. Module `Sales` (Sale, SaleItem, SalePayment, remboursement/annulation, `idempotency_key` — voir [database.md](database.md) §7).

Critère de sortie : un flux complet panier → vente → mouvement de stock → mouvement de caisse → reçu est testé de bout en bout pour au moins un métier (ex: alimentation générale), y compris les tests d'atomicité et d'idempotence ([testing.md](testing.md) §6 bis).

## Phase 5 — Modules spécifiques par métier
14. Module `Suppliers`.
15. Module `Employees`.
16. Module `Appointments` (coiffure, salon de beauté) — appliquer la dépendance de feature `appointments → services, employees` ([features.md](features.md) §7).
17. Module `Orders` (restaurant), avec entité `Table` (ajoutée à l'audit, [database.md](database.md) §13) et conversion vers `Sales`.

## Phase 6 — Support et pilotage
18. Module `Reports` (agrégations sur les modules déjà livrés).
19. Module `Notifications`.
20. Endpoints d'administration plateforme (`platform_admin`, voir [permissions.md](permissions.md) §6) : activer/désactiver domaines et features, gérer les plans.

## Phase 7 — Durcissement
21. Documentation OpenAPI générée sur l'ensemble des endpoints livrés (voir [api-conventions.md](api-conventions.md) §7).
22. Audit de sécurité complet ([security.md](security.md)) sur l'ensemble du périmètre livré.
23. Outillage statique (`phpstan`/`larastan`) et éventuellement `deptrac` si le respect des frontières de modules montre des signes d'érosion ([risks.md](risks.md) #3).
24. Décision fournisseur de paiement pour `Subscriptions` (hors périmètre jusqu'ici).

## Ce qui ne doit jamais être court-circuité, quelle que soit la pression de planning

- Aucun endpoint retournant une ressource par ID sans Policy associée (Phase 1 onward).
- Aucun nouveau module métier livré sans sa suite de tests d'isolation multi-tenant.
- Aucune feature créée en base sans le middleware/module réel qui la sous-tend (voir [risks.md](risks.md) #5).
