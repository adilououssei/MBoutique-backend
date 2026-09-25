# Schéma conceptuel de la base de données

> **Mise à jour (audit architectural du 2026-09-06)** : ce document a été corrigé suite à l'audit — voir [audit-2026-09.md](audit-2026-09.md) pour le détail des constats. Ajouts : conventions de typage financier (§0), `BusinessUser` (§2), `tracksStock()`/morph map sur `Sellable` (§5), types de mouvement et verrouillage transactionnel du stock (§6/§11), clé d'idempotence sur `Sale` (§7), entité `Table` pour le domaine restaurant (§13).
>
> **Mise à jour (implémentation Phase 2, 2026-09-07)** : §4 (Features) implémenté — voir [feature-gate.md](feature-gate.md) pour le détail complet. Écarts assumés par rapport à ce document : `code`/`label` → `slug`/`name` (cohérence avec `Store`), `DomainFeature`/`FeatureDependency` en clé surrogate + `unique` plutôt qu'en clé composite littérale. `Store.business_domain_id` est maintenant une colonne réelle (migration séparée, voir §Store), `NOT NULL`, un domaine étant obligatoire dès la création.

Convention générale : clés primaires `id` (bigint auto-increment) sauf mention contraire, `created_at`/`updated_at` sur toutes les tables, `deleted_at` (soft delete) sur les entités qu'on ne doit jamais supprimer physiquement pour des raisons d'audit ou de traçabilité financière (Business, Store, User, Product, Service, Customer, Sale, StockMovement, CashTransaction). Toute table métier scopée à une boutique porte une colonne `store_id` indexée et utilise le trait `BelongsToStore` (voir [multi-tenancy.md](multi-tenancy.md)).

## 0. Conventions de typage (financier et autres)

Ces règles s'appliquent à **toutes** les tables de ce document, sans exception, et priment sur toute mention plus vague plus bas (ex: "prix", "montant") :

| Nature de la donnée | Type SQL | Raison |
|---|---|---|
| Tout montant monétaire (`price`, `cost_price`, `subtotal`, `total`, `amount`, `opening_balance`, ...) | `DECIMAL(12,2)` (jamais `FLOAT`/`DOUBLE`) | Un flottant binaire ne représente pas exactement des décimales base 10 (ex: `0.1 + 0.2 != 0.3`) ; sur des totaux de caisse cumulés, l'erreur d'arrondi devient un écart de caisse réel. `DECIMAL` est un type exact stocké en base. Ce choix reste valable même pour une devise sans sous-unité (XOF/XAF) — le montant est alors toujours entier avec `.00`. |
| Quantités (`quantity` sur `StockMovement`, `SaleItem`, `OrderItem`) | `DECIMAL(12,3)` | Doit pouvoir représenter une quantité fractionnaire (ex: 1.5 kg de viande), pas seulement des entiers. |
| Taux (`tax_rate`) et pourcentages (remise en %) | `DECIMAL(5,2)` | Suffisant (0.00 à 999.99%) et exact, mêmes raisons que les montants. |
| Statuts (`status`, `type`) | Colonne `string` avec valeurs contrôlées côté application (PHP `enum` backé), pas de type `ENUM` MySQL natif | Un `enum` PHP (`BackedEnum`) donne l'autocomplétion et la sécurité de type en code ; le type `ENUM` MySQL rend l'ajout d'une valeur coûteux (ALTER TABLE) et n'est pas portable. `string` + validation applicative + cast Eloquent `enum` cumule le meilleur des deux. |
| Arithmétique monétaire côté PHP | Jamais d'opérateurs flottants natifs sur un montant qui a transité en `float` | Recalcul serveur systématique à partir des `DECIMAL` (castés en string ou via `brick/money`/`bcmath` à l'implémentation), jamais un `$a + $b` sur des `float` PHP pour un total affiché ou persisté. |
| Clés étrangères | `unsignedBigInteger` + contrainte `foreign()->constrained()` explicite | — |
| Suppression d'une ligne référencée par de l'historique (Product référencé par SaleItem, Customer référencé par Sale, ...) | `restrictOnDelete()`, jamais `cascadeOnDelete()`, sur toute relation vers une table auditée | Une suppression en cascade sur ce genre de relation détruirait silencieusement de l'historique financier ; l'entité "parente" doit être soft-deleted (elle reste en base, désactivée), jamais supprimée physiquement tant qu'elle a de l'historique. |

## 1. Vue d'ensemble des relations

```
User ──< StoreUser >── Store ──< Business >── BusinessUser >── User
                          │          │
                          │          └──< Subscription >── Plan
                          │
                          ├──< StoreFeatureOverride   Store >── BusinessDomain ──< DomainFeature >── Feature
                          │
                          ├──< Product / Service (Catalog, implémentent Sellable)
                          │        │
                          │        └──< StockMovement (Product uniquement)
                          │
                          ├──< Customer, Supplier, Employee
                          │
                          ├──< Sale ──< SaleItem (Sellable polymorphique)
                          │       └──< SalePayment
                          │
                          ├──< CashRegisterSession ──< CashTransaction
                          │
                          ├──< Appointment (Service + Employee + Customer)
                          │
                          └──< Order ──< OrderItem  (─> Sale à la finalisation)

Role/Permission (spatie) sont scopés par `store_id` (team) et rattachés à User via model_has_roles.
```

## 2. Tenancy

### Business
Responsabilité : l'entité juridique/commerciale qui souscrit un abonnement et peut posséder plusieurs boutiques.
- Attributs : `id`, `name`, `owner_user_id`, `legal_name` (nullable), `country`, `currency` (ISO 4217, ex: `XOF`), `timezone`, `status` (`active`, `suspended`), `created_at`, `updated_at`.
- Relations : `hasMany(Store)`, `hasOne(Subscription)` (abonnement courant), `belongsTo(User, 'owner_user_id')`.
- Contraintes : `owner_user_id` obligatoire et référence un `User` existant.
- Index : `owner_user_id`.
- Règles métier : la suppression d'un Business est un soft delete qui doit cascader (logiquement, pas en SQL `ON DELETE CASCADE`) vers ses Stores pour éviter une boutique orpheline active.

### BusinessUser (ajouté suite à l'audit)
Responsabilité : distinguer les actions **au niveau du Business** (créer une nouvelle boutique, gérer l'abonnement, voir les rapports consolidés) des actions **au niveau d'un Store** (gérées par `StoreUser` + spatie/laravel-permission, voir §3). `spatie/laravel-permission` en mode teams est scopé par `store_id` : il ne peut donc pas exprimer nativement "cet utilisateur peut créer une boutique dans ce Business" puisqu'aucune boutique n'existe encore au moment de vérifier ce droit. Voir [permissions.md](permissions.md) §8 pour la justification complète.
- Attributs : `id`, `business_id`, `user_id`, `role` (`owner`, `admin`), `created_at`.
- Relations : `belongsTo(Business)`, `belongsTo(User)`.
- Contraintes : unique (`business_id`, `user_id`).
- Règles métier : à la création d'un `Business`, une ligne `BusinessUser(role: owner)` est créée automatiquement pour le créateur — `Business.owner_user_id` reste comme raccourci dénormalisé pratique (ex: affichage rapide, contact facturation) mais **n'est plus l'unique source d'autorisation** ; toute vérification de droit business-level interroge `BusinessUser`, jamais uniquement `owner_user_id`. Un `role: admin` peut créer des boutiques et voir les rapports consolidés mais pas gérer l'abonnement/facturation (seul `owner` le peut).
- Règles métier : à la création d'un `Store` sous un `Business`, chaque `BusinessUser` de ce `Business` reçoit automatiquement un `StoreUser(status: active)` sur la nouvelle boutique, avec le rôle spatie "Propriétaire" (pour `owner`) ou "Administrateur" (pour `admin`) — c'est le mécanisme qui permet nativement à "un utilisateur qui gère plusieurs boutiques" (vision §1 du besoin) d'accéder à toute nouvelle boutique de son Business sans invitation manuelle répétée.

### Store
Responsabilité : une boutique/point de vente concret, unité d'isolation tenant pour toutes les données opérationnelles.
- Attributs : `id`, `business_id`, `business_domain_id`, `name`, `slug` (unique), `address`, `phone`, `currency` (peut différer du Business si multi-pays), `timezone`, `status` (`active`, `inactive`), `settings` (JSON — préférences d'affichage, format de reçu, etc.).
- Relations : `belongsTo(Business)`, `belongsTo(BusinessDomain)`, `hasMany(StoreUser)`, `hasMany(StoreFeatureOverride)`, et toutes les entités opérationnelles (`hasMany(Product)`, `hasMany(Sale)`, ...).
- Contraintes : `business_domain_id` obligatoire (une boutique doit toujours avoir un domaine métier, potentiellement `autre`).
- Index : `business_id`, `business_domain_id`, `slug` (unique).
- Règles métier : `store_id` est le "team" spatie/laravel-permission — voir [permissions.md](permissions.md). C'est aussi la valeur portée par `TenantContext` pendant toute la requête.
- **Phase 2** : `business_domain_id` est désormais une colonne réelle (migration séparée `add_business_domain_id_to_stores_table`, ajoutée après `business_domains` plutôt qu'en modifiant la migration Phase 1 des `stores`, comme annoncé). `NOT NULL`, validé actif à la création (`CreateStoreRequest`).

### StoreUser
Responsabilité : la relation d'appartenance d'un `User` à un `Store`, incluant les invitations en attente.
- Attributs : `id`, `store_id`, `user_id`, `status` (`invited`, `active`, `revoked`), `invited_by_user_id` (nullable), `invited_at`, `joined_at` (nullable).
- Relations : `belongsTo(Store)`, `belongsTo(User)`.
- Contraintes : unique sur (`store_id`, `user_id`).
- Index : `store_id`, `user_id`, (`store_id`,`user_id`) unique composite.
- Règles métier : c'est l'existence d'un `StoreUser` avec `status = active` qui autorise l'accès à un `Store` — voir [multi-tenancy.md](multi-tenancy.md). Les rôles/permissions de l'utilisateur sur ce store sont gérés séparément par spatie (mode teams), pas par une colonne `role` ici, pour rester extensible.

## 3. Authorization (spatie/laravel-permission, mode teams)

### Role / Permission
Tables fournies par le package (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`), toutes porteuses de `store_id` (renommage de `team_id`, voir `config/permission.php`). Pas de migration custom nécessaire au-delà de la migration publiée par le package. Détail complet dans [permissions.md](permissions.md).

## 4. Features (Domain → Feature) — **implémenté en Phase 2**, voir [feature-gate.md](feature-gate.md)

### BusinessDomain
Responsabilité : un type de métier (alimentation générale, boucherie, coiffeur, ...).
- Attributs (implémentés) : `id`, `slug` (unique, ex: `hair_salon`), `name`, `description` (nullable), `icon` (nullable), `is_active` (défaut `true`), timestamps.
- Relations : `hasMany(Store)`, `hasMany(DomainFeature)`.
- Index : `slug` (unique).
- Règle métier confirmée en implémentation : `is_active` ne gate que la sélection du domaine à la création d'une boutique (`CreateStoreRequest`) — il n'intervient **pas** dans la résolution `FeatureGate` d'une boutique déjà créée. Voir [feature-gate.md](feature-gate.md) §2.

### Feature
Responsabilité : une capacité activable (ex: `products`, `appointments`, `cash_register`).
- Attributs (implémentés) : `id`, `slug` (unique), `name`, `description` (nullable), `is_active` (défaut `true`), timestamps.
- Relations : `hasMany(DomainFeature)`, `hasMany(FeatureDependency, 'feature_id')`.
- Index : `slug` (unique).
- Pas de colonne `metadata`/`configuration` : rien n'en a eu besoin à ce stade, ajoutée seulement si un cas concret l'exige (éviter l'abstraction spéculative).

### DomainFeature
Responsabilité : la feature est-elle activée **par défaut** pour un domaine donné.
- Attributs : `id` (surrogate, voir note ci-dessous), `business_domain_id`, `feature_id`, `is_default_enabled` (bool, défaut `true`), timestamps.
- Contraintes : `unique(business_domain_id, feature_id)` — remplace la clé composite littérale envisagée initialement (le support des clés composites d'Eloquent ne justifie pas la complexité pour une garantie d'unicité identique).

### FeatureDependency (ajouté à l'audit, implémenté en Phase 2)
Responsabilité : `feature_id` ne peut être active que si `depends_on_feature_id` l'est aussi (voir [features.md](features.md) §7).
- Attributs : `id`, `feature_id`, `depends_on_feature_id`, timestamps.
- Contraintes : `unique(feature_id, depends_on_feature_id)`, `depends_on_feature_id` en `restrictOnDelete` (on ne supprime pas une feature dont une autre dépend).

### StoreFeatureOverride
Responsabilité : permet à une boutique précise de déroger au défaut de son domaine (activer une feature normalement absente de son métier, ou désactiver une feature normalement présente).
- Attributs : `id`, `store_id`, `feature_id`, `is_enabled` (pas de défaut — toujours fourni explicitement), timestamps.
- Contraintes : unique (`store_id`, `feature_id`).
- **Tenant-scopé** : utilise `BelongsToStore` comme tout modèle métier — `store_id` toujours forcé serveur, jamais lisible/écrivable inter-store (testé, voir [testing.md](testing.md)).
- Règles métier : la disponibilité effective d'une feature pour un store = `StoreFeatureOverride` si présent, sinon `DomainFeature.is_default_enabled`, à condition que `Feature.is_active` soit vrai ET que l'abonnement (`Subscriptions`) l'autorise. Voir [feature-gate.md](feature-gate.md) pour l'algorithme définitif et tranché.

## 5. Catalog

### Sellable (contrat, pas une table)
Interface implémentée par `Product` et `Service` : `getSellablePrice()`, `getSellableLabel()`, `isTaxable()`, `getStoreId()`, **`tracksStock(): bool`** (ajouté suite à l'audit — retourne `$this->track_stock` sur `Product`, `false` en dur sur `Service`). Permet à `Sales`/`Inventory` de décider d'écrire un `StockMovement` sans jamais faire `if ($sellable instanceof Product)` — exactement le genre de branchement conditionnel par type que le projet cherche à éviter, ici appliqué à la vente plutôt qu'au domaine métier. Voir §10.

**Point d'audit** : `SaleItem.sellable_type`/`OrderItem.sellable_type` doivent être enregistrés via un morph map explicite (`Relation::enforceMorphMap(['product' => Product::class, 'service' => Service::class])`, déclaré dans le `ServiceProvider` du module `Catalog`) plutôt que le nom de classe PHP complet. Sans cela, un renommage ou un déplacement futur de `App\Modules\Catalog\Models\Product` casserait silencieusement toutes les lignes historiques déjà écrites en base.

### Product
- Attributs : `id`, `store_id`, `product_category_id` (nullable), `name`, `sku` (nullable, unique par store), `barcode` (nullable), `price`, `cost_price` (nullable), `is_taxable`, `tax_rate` (nullable), `unit` (ex: `kg`, `pièce`), `track_stock` (bool — un produit peut exister sans suivi de stock, ex: article divers), `is_active`.
- Relations : `belongsTo(ProductCategory)`, `hasMany(StockMovement)`, `morphMany(SaleItem, 'sellable')`.
- Index : `store_id`, (`store_id`,`sku`) unique, `barcode`.
- Règles métier : si `track_stock = true`, toute vente doit vérifier la disponibilité (sauf configuration "vente en négatif" autorisée par la boutique).

### ProductCategory
- Attributs : `id`, `store_id`, `name`, `parent_id` (nullable, catégories imbriquées).
- Index : `store_id`, `parent_id`.

### Service
- Attributs : `id`, `store_id`, `service_category_id` (nullable), `name`, `price`, `duration_minutes` (utilisé par `Appointments`), `is_taxable`, `tax_rate`, `is_active`.
- Relations : `belongsTo(ServiceCategory)`, `morphMany(SaleItem, 'sellable')`, `hasMany(Appointment)`.
- Index : `store_id`.

### ServiceCategory
Symétrique à `ProductCategory`.

## 6. Inventory

> **Révision (Phase 4.1, 2026-09-24)** : le prompt de cette phase a demandé explicitement un modèle `Stock` séparé plutôt que la colonne dénormalisée `products.current_stock` décrite plus bas dans cette section et en §11. Décision actée avec l'utilisateur : suivre la nouvelle demande. Ce qui suit est **remplacé** par la structure implémentée, détaillée dans [inventory.md](inventory.md) — cette section garde le texte d'origine barré-en-substance (plutôt que supprimé) pour que la trace de la décision reste lisible.
>
> **Implémenté à la place** : `Stock` (`id`, `store_id`, `product_id`, `quantity` `DECIMAL(12,3)`, `minimum_quantity` `DECIMAL(12,3)` nullable ; unique `(store_id, product_id)`) porte l'état courant. `StockMovement` reste le ledger append-only mais référence `stock_id` en plus de `product_id`, et n'a pas de `unit_cost` (reporté — aucun module Achats/Suppliers ne l'utilise encore). Les types retenus : `initial`, `purchase`, `sale`, `return_in`, `adjustment_in`, `adjustment_out`, `stocktake`, `loss` — pas de `transfer_in`/`transfer_out` (non demandés par cette phase, restent une conception documentée mais non implémentée). Voir [inventory.md](inventory.md) pour le détail complet (concurrence, immutabilité, permissions, contrat futur avec Sales).

### StockMovement (conception d'origine, remplacée ci-dessus)
Responsabilité : ledger append-only de tout changement de quantité. **Il n'existe pas de colonne "stock actuel" éditée directement** ; voir §11.
- Attributs : `id`, `store_id`, `product_id`, `type` (`purchase`, `sale`, `loss`, `adjustment`, `return_in`, `stocktake`, `transfer_in`, `transfer_out`, `initial`), `quantity` (signé : positif = entrée, négatif = sortie), `unit_cost` (nullable, utile pour le coût moyen pondéré), `reference_type`/`reference_id` (morph, pointe vers `Sale`, `SaleItem`, un futur `PurchaseOrder`, ou rien pour un ajustement manuel), `note` (nullable), `created_by_user_id`.
- Relations : `belongsTo(Product)`, `morphTo(reference)`.
- Index : `store_id`, `product_id`, (`product_id`,`created_at`) pour le calcul de solde par période, `reference_type`+`reference_id`.
- Règles métier : jamais d'`UPDATE` sur une ligne existante ; une correction s'exprime comme un nouveau mouvement de type `adjustment`. `return_in` (retour client, distinct de `adjustment` pour du reporting propre : "combien de perte" vs "combien de retour") et `stocktake` (écart constaté lors d'un inventaire physique, distinct d'un `adjustment` ad hoc pour pouvoir dater/grouper une campagne d'inventaire) sont des types dédiés ajoutés suite à l'audit — demande explicite du besoin métier ("retours", "inventaires"). `transfer_out`/`transfer_in` sont la **seule** exception délibérée au principe d'isolation stricte par store : un transfert crée un mouvement `transfer_out` sur le store source et `transfer_in` sur le store destination, autorisé uniquement si les deux stores appartiennent au même `business_id`, derrière une permission dédiée `inventory.transfer` vérifiée sur le store source, et journalisé (voir [multi-tenancy.md](multi-tenancy.md) §7 "Exception contrôlée : transferts inter-boutiques"). Le solde courant = `SUM(quantity)` pour le produit, mis en cache dans une colonne dénormalisée `products.current_stock` recalculée à l'écriture (voir §11) pour éviter un `SUM` à chaque lecture de liste produits.

## 7. Sales

> **Révision (Phase 4.3, 2026-09-26)** : même décision qu'en Phase 4.1/4.2 — le prompt de cette phase liste explicitement `SaleItem.product_id` (pas de morph `sellable_type`/`sellable_id`). `idempotency_key` était déjà anticipé ici avant même son implémentation ; aucun écart sur ce point.
>
> **Implémenté à la place** : `SaleItem` référence `product_id` directement (`Product` uniquement — `pricing_mode`/détail-gros n'a de sens que pour un `Product`, jamais pour un `Service`). Champs renommés vers la nomenclature du prompt : `discount_amount`/`total_amount` (pas `discount_total`/`tax_total`/`total` — pas de fiscalité, cohérent avec l'absence de taxe sur `Product`), `product_name`/`unit_price` (pas `label_snapshot`/`unit_price_snapshot` — le nom exprime déjà qu'il s'agit d'un instantané, documenté dans le modèle plutôt que dans le nom de colonne). Pas de `SalePayment` : un seul `payment_method` sur `Sale` (`cash` uniquement utilisable cette phase), le paiement mixte multi-lignes reste une conception future si un vrai module Payments est construit. Voir [sales.md](sales.md) pour le détail complet (Checkout, verrouillage, idempotence, intégrations Inventory/CashRegister).

### Sale (conception d'origine, remplacée ci-dessus)
- Attributs : `id`, `store_id`, `customer_id` (nullable — vente au comptoir sans client identifié), `cash_register_session_id`, `sold_by_user_id`, `status` (`completed`, `cancelled`, `refunded`, `partially_refunded`), `subtotal`, `discount_total`, `tax_total`, `total`, `idempotency_key` (nullable, généré par le client mobile), `sold_at`.
- Relations : `hasMany(SaleItem)`, `hasMany(SalePayment)`, `belongsTo(Customer)`, `belongsTo(CashRegisterSession)`.
- Index : `store_id`, `customer_id`, `sold_at`, `status`, (`store_id`,`idempotency_key`) unique.
- Règles métier : `total` est toujours dérivé de `SaleItem` + remises + taxes, jamais saisi manuellement (recalcul serveur systématique, jamais fait confiance à un total envoyé par le client mobile). `idempotency_key` (ajouté suite à l'audit) : un client mobile sur réseau instable peut soumettre deux fois la même requête de création de vente (double tap, retry automatique) ; le client génère un UUID par tentative de vente et le backend retourne la `Sale` déjà créée (au lieu d'en recréer une seconde) si la même paire (`store_id`, `idempotency_key`) existe déjà — évite une double vente et un double décrément de stock.

### SaleItem (conception d'origine, remplacée ci-dessus)
- Attributs : `id`, `sale_id`, `sellable_type`, `sellable_id` (morph vers `Product` ou `Service`), `label_snapshot` (nom au moment de la vente, car un produit peut être renommé/supprimé après coup), `quantity`, `unit_price_snapshot`, `discount_amount`, `tax_amount`, `line_total`.
- Règles métier : capture un instantané (`_snapshot`) du prix et du libellé pour que l'historique de vente reste correct même si le produit change de prix ou est supprimé plus tard (jamais de suppression physique d'un `Product` référencé — soft delete uniquement).

### SalePayment
- Attributs : `id`, `sale_id`, `method` (`cash`, `mobile_money`, `card`, `bank_transfer`, `other`), `amount`, `reference` (nullable, ex: id de transaction mobile money), `paid_at`.
- Règles métier : `SUM(SalePayment.amount) = Sale.total` requis pour qu'une vente passe en `completed` ; permet nativement le paiement mixte (plusieurs lignes de paiement pour une même vente).

## 8. CashRegister

> **Révision (Phase 4.2, 2026-09-25)** : le prompt de cette phase a demandé explicitement une entité `CashRegister` séparée (une boutique peut avoir plusieurs caisses physiques) là où la conception d'origine ci-dessous scopait `CashRegisterSession` directement au `store_id`, sous-entendant une seule caisse par boutique. Même décision qu'en Phase 4.1 (Inventory, voir §6) : la demande explicite de la phase l'emporte, documentée ici plutôt que silencieusement contredite.
>
> **Implémenté à la place** : `CashRegister` (`id`, `store_id`, `name`, `code` nullable unique par store, `is_active`, `open_session_id` — pointeur applicatif, pas de FK, vers la session actuellement ouverte) porte l'identité de la caisse. `CashRegisterSession` reste l'historique d'utilisation mais référence `cash_register_id` en plus de `store_id`, et les noms de champs suivent la nomenclature du prompt (`opening_amount`/`expected_closing_amount`/`actual_closing_amount`/`difference`/`closing_note`, pas `*_balance`). `CashTransaction` est remplacé par `CashMovement`, types `opening`/`cash_in`/`cash_out`/`adjustment`/`sale`/`refund` (pas `sale_in`/`refund_out`/`expense_out`/`deposit_in`/`withdrawal_out`). Voir [cash-register.md](cash-register.md) pour le détail complet (une seule session ouverte, concurrence, permissions, contrat futur avec Sales).

### CashRegisterSession (conception d'origine, remplacée ci-dessus)
- Attributs : `id`, `store_id`, `opened_by_user_id`, `closed_by_user_id` (nullable), `opening_balance`, `expected_closing_balance` (calculé), `actual_closing_balance` (nullable, saisi à la fermeture), `status` (`open`, `closed`), `opened_at`, `closed_at` (nullable).
- Règles métier : une seule session `open` à la fois par store (contrainte applicative, pas SQL). L'écart `actual - expected` à la fermeture est un signal à faire remonter dans `Reports`.

### CashTransaction (conception d'origine, remplacée ci-dessus)
- Attributs : `id`, `store_id`, `cash_register_session_id`, `type` (`sale_in`, `refund_out`, `expense_out`, `deposit_in`, `withdrawal_out`), `amount`, `reference_type`/`reference_id` (morph vers `Sale` si applicable), `note`, `created_by_user_id`.
- Index : `cash_register_session_id`, `store_id`.

## 9. Customers, Suppliers, Employees

### Customer
- Attributs : `id`, `store_id`, `first_name`, `last_name`, `phone` (nullable), `email` (nullable), `notes`, `loyalty_points` (nullable, extensible).
- Index : `store_id`, `phone`.

### Supplier
- Attributs : `id`, `store_id`, `name`, `phone`, `email`, `notes`.
- Relations : référencé par `StockMovement.reference` (type `purchase`) — pas de FK directe pour rester découplé ; à réévaluer si un module "Achats/PurchaseOrder" apparaît dans la roadmap.

### Employee
- Attributs : `id`, `store_id`, `store_user_id` (nullable — lien optionnel vers un compte applicatif), `first_name`, `last_name`, `position`, `phone`, `hired_at`, `status` (`active`, `inactive`).
- Index : `store_id`, `store_user_id`.

## 10. Produits vs Services — décision d'architecture

**Problème** : certains métiers vendent des biens physiques (stock, code-barres), d'autres des prestations (durée, disponibilité), d'autres les deux (restaurant : plat = produit sans stock fin réel mais avec ingrédients, coiffeur : produit capillaire + service coupe).

| Option | Description | Avantages | Inconvénients |
|---|---|---|---|
| A. Table unique polymorphique `catalog_items` avec `type` + colonnes nullable | Un seul modèle, `type` discrimine | Une seule table à interroger pour "tout ce qui est vendable" | Colonnes nullable qui ne s'appliquent qu'à un type (`duration_minutes` n'a pas de sens pour un produit), requêtes et validations pleines de conditions selon `type` |
| B. Deux modèles distincts (`Product`, `Service`) + table pivot pour les ventes mixtes | Chaque modèle a exactement ses colonnes | Modèles propres, migrations propres, chacun évolue indépendamment (le stock n'a de sens que pour Product) | `SaleItem` doit référencer "l'un ou l'autre" |
| **C. Deux modèles distincts + contrat `Sellable` + relation polymorphique côté `SaleItem` (retenu)** | Comme B, mais `SaleItem.sellable_type/sellable_id` unifie la référence sans table pivot | Cumule la propreté de B et la simplicité de requête de A pour le seul endroit qui a réellement besoin de traiter les deux de façon uniforme (la vente) | Une relation polymorphique de plus dans le schéma (coût mineur, bien maîtrisé par Eloquent) |

**Décision : option C.** Elle évite la complexité inutile d'une table fourre-tout (rejetée explicitement par la demande du projet) tout en donnant à `Sales`/`Orders` un point d'intégration unique. Un "produit/service combiné" (ex: forfait coiffure + vente de shampoing) se modélise simplement comme deux `SaleItem` dans la même `Sale`, pas comme une nouvelle entité.

## 11. Stock — décision d'architecture

> **Révision (Phase 4.1, 2026-09-24)** : voir la note en tête de §6. Le principe ci-dessous ("jamais de mutation directe, toujours un mouvement qui la justifie, jamais de `SUM()` à la lecture") reste intégralement vrai et appliqué — seul le support physique du cache change : un modèle `Stock` séparé plutôt qu'une colonne sur `products`. Le verrou de concurrence (§ci-dessous) porte donc sur la ligne `Stock`, pas sur la ligne `Product`. Voir [inventory.md](inventory.md) §7 pour le code réellement implémenté.

**Rejeté explicitement** : `product.stock = product.stock - 1` (aucune traçabilité, aucune possibilité d'audit ou de correction).

**Retenu à l'origine** : `StockMovement` en ledger append-only (§6) + colonne dénormalisée `products.current_stock` recalculée **à l'écriture** de chaque mouvement (dans la même transaction), pour que la lecture (liste produits, vérification de disponibilité avant vente) reste une simple lecture de colonne et non un `SUM()` sur potentiellement des milliers de mouvements. Le mouvement reste la source de vérité ; la colonne dénormalisée est un cache reconstructible (commande artisan de recalcul à prévoir pour la roadmap, en cas de divergence détectée). **Remplacé en Phase 4.1** par un modèle `Stock` séparé portant ce même cache (voir la révision ci-dessus) — le raisonnement sur le "pourquoi un cache plutôt qu'un `SUM()`" reste identique, seul l'emplacement du cache change.

Cela couvre nativement tous les cas cités dans le besoin (achat +100, vente -5, perte -2, réappro +20, correction -1, retour, inventaire) comme des lignes du même mécanisme, sans entités séparées `StockEntry`/`StockExit`/`Adjustment` qui dupliqueraient la même structure sous des noms différents — un `type` suffit et simplifie les requêtes de reporting ("tous les mouvements de ce produit, quel que soit leur type").

**Concurrence (point d'audit, verrou déplacé sur `Stock` en Phase 4.1)** : deux ventes simultanées sur le même produit peuvent chacune lire `quantity = 5` et accepter chacune une vente de 3, aboutissant à un stock réel de -1 alors que chaque vérification individuelle semblait correcte (race condition classique "lire-puis-écrire"). Parade obligatoire, appliquée dans `InventoryService` (voir [inventory.md](inventory.md) §7) : verrouiller la ligne `Stock` en lecture avant de vérifier la disponibilité, à l'intérieur de la même transaction qui insère le mouvement et met à jour `quantity` — code réel :

```php
DB::transaction(function () use ($product, $quantity, ...) {
    $stock = Stock::where('product_id', $product->id)->lockForUpdate()->first(); // SELECT ... FOR UPDATE

    if ($stock === null) {
        throw new StockNotInitializedException(...);
    }
    if (bccomp($stock->quantity, $quantity, 3) < 0) {
        throw InsufficientStockException::forProduct($product, $stock->quantity, $quantity);
    }

    StockMovement::create([...]);
    $stock->update(['quantity' => bcsub($stock->quantity, $quantity, 3)]); // même transaction, verrou déjà tenu
});
```

Le verrou (`lockForUpdate`) sérialise les écritures concurrentes sur la même ligne `Product` — la seconde transaction attend que la première commit avant de lire à son tour un `current_stock` à jour. Recommandation complémentaire : une commande artisan planifiée de réconciliation (`SUM(stock_movements.quantity)` vs `products.current_stock`) pour détecter toute divergence malgré cette protection (bug futur, opération hors service) — voir [risks.md](risks.md).

## 12. Appointments

### Appointment
- Attributs : `id`, `store_id`, `service_id`, `employee_id` (nullable si non assigné), `customer_id`, `starts_at`, `ends_at`, `status` (`booked`, `confirmed`, `completed`, `cancelled`, `no_show`), `notes`.
- Index : `store_id`, `employee_id`+`starts_at` (recherche de disponibilité), `customer_id`.
- Règles métier : `ends_at` dérivé de `starts_at + service.duration_minutes` sauf ajustement manuel ; un chevauchement pour le même `employee_id` doit être rejeté en validation applicative (pas de contrainte SQL d'exclusion portable simplement sous MySQL — vérification en transaction avec verrou).

## 13. Orders

### Table (ajouté suite à l'audit)
Responsabilité : représente une table physique d'un restaurant (ou un poste de service équivalent), pour permettre une vue "plan de salle" (quelles tables sont libres/occupées) sans avoir à parcourir les `Order` ouvertes. Absent de la conception initiale, où seul un champ texte `table_number` existait sur `Order` — insuffisant pour répondre à l'exemple du besoin ("Restaurant → ... tables ...", `tables` cité comme feature à part entière au même titre que `orders`).
- Attributs : `id`, `store_id`, `label` (ex: "Table 4", "Comptoir 2"), `capacity` (nullable), `status` (`free`, `occupied`, `reserved`, `out_of_service`).
- Relations : `hasMany(Order)`.
- Index : `store_id`.
- Règles métier : `status` est dérivé/synchronisé par le service `Orders` à l'ouverture/fermeture d'une commande liée (pas de double source de vérité manuelle) ; reste une entité du module `Orders` (pas un module séparé) car elle n'a de sens que pour les domaines qui activent la feature `tables`, elle-même dépendante de `orders` (voir [features.md](features.md) §7 "Dépendances entre features").

### Order / OrderItem
- `Order` : `id`, `store_id`, `customer_id` (nullable), `table_id` (nullable, FK vers `Table` — remplace le `table_number` texte initial), `type` (`dine_in`, `delivery`, `pickup`), `status` (`pending`, `preparing`, `ready`, `served`, `completed`, `cancelled`), `sale_id` (nullable, renseigné à la finalisation).
- `OrderItem` : `id`, `order_id`, `sellable_type`/`sellable_id` (même contrat `Sellable` que `SaleItem`), `quantity`, `unit_price_snapshot`, `notes` (ex: "sans oignons").
- Règles métier : à la finalisation, `Orders` crée une `Sale` + ses `SaleItem` à partir des `OrderItem`, puis renseigne `Order.sale_id` — pas de duplication de logique de calcul de prix (réutilise le service de `Sales`). Un domaine `delivery`/`pickup` pur (pas de salle physique) n'active pas la feature `tables` et laisse `table_id` toujours `null`.

## 14. Subscriptions — **version minimale implémentée en Phase 2**

Voir [subscriptions.md](subscriptions.md) pour le détail complet de `Plan`, `Subscription`, et la relation avec les limites/features.

- `plans` : `id`, `code` (unique), `name`, `price_monthly` (`DECIMAL(12,2)`, défaut 0 — champ posé mais aucun paiement réel), `price_yearly` (nullable), `max_stores`/`max_users_per_store`/`max_products_per_store` (nullable = illimité), `is_active`.
- `subscriptions` : `id`, `business_id` (**unique** — un seul abonnement actif par Business), `plan_id`, `status` (`trialing`/`active`/`past_due`/`cancelled`), `trial_ends_at`, `current_period_starts_at`, `current_period_ends_at`, `cancelled_at`.
- `plan_features` : pivot pur (`plan_id`, `feature_id`, clé primaire composite) — pas de modèle Eloquent dédié, `Plan::features()` en `belongsToMany` standard, aucune donnée pivot supplémentaire à porter (contrairement à `DomainFeature`).
- Un `Business` créé en Phase 1 n'a **aucune** `Subscription` par défaut — voir [feature-gate.md](feature-gate.md) §4 pour la conséquence directe sur `FeatureGate` (fail-open, pas fail-closed).

## 15. Notifications

### Notification (log)
- Attributs : `id`, `user_id`, `store_id` (nullable, certaines notifications sont plateforme), `type`, `channel` (`mail`, `push`, `sms`), `payload` (JSON), `read_at` (nullable), `sent_at`.
- Utilise la table `notifications` native de Laravel si le canal `database` est utilisé, ou une table dédiée si on a besoin de champs supplémentaires — décision à prendre au moment de l'implémentation selon les canaux réellement utilisés (hors périmètre de cette phase de conception).

## Ce qui n'est délibérément pas encore figé

Le nombre exact de colonnes JSON de configuration (`settings`), la stratégie de devise multi-pays au sein d'un même `Business`, et le détail des `PlanLimit` (voir [subscriptions.md](subscriptions.md)) seront affinés à l'implémentation de chaque module, sans remettre en cause les relations structurantes décrites ici.
