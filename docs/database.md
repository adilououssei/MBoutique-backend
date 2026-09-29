# Schéma conceptuel de la base de données

> **Mise à jour (audit architectural du 2026-09-06)** : ce document a été corrigé suite à l'audit — voir [audit-2026-09.md](audit-2026-09.md) pour le détail des constats. Ajouts : conventions de typage financier (§0), `BusinessUser` (§2), `tracksStock()`/morph map sur `Sellable` (§5), types de mouvement et verrouillage transactionnel du stock (§6/§11), clé d'idempotence sur `Sale` (§7), entité `Table` pour le domaine restaurant (§13).
>
> **Mise à jour (implémentation Phase 2, 2026-09-07)** : §4 (Features) implémenté — voir [feature-gate.md](feature-gate.md) pour le détail complet. Écarts assumés par rapport à ce document : `code`/`label` → `slug`/`nom` (cohérence avec `Store`), `DomainFeature`/`FeatureDependency` en clé surrogate + `unique` plutôt qu'en clé composite littérale. `Store.domaine_activite_id` est maintenant une colonne réelle (migration séparée, voir §Store), `NOT NULL`, un domaine étant obligatoire dès la création.

Convention générale : clés primaires `id` (bigint auto-increment) sauf mention contraire, `created_at`/`updated_at` sur toutes les tables, `deleted_at` (soft delete) sur les entités qu'on ne doit jamais supprimer physiquement pour des raisons d'audit ou de traçabilité financière (Business, Store, User, Product, Service, Customer, Sale, StockMovement, CashTransaction). Toute table métier scopée à une boutique porte une colonne `boutique_id` indexée et utilise le trait `BelongsToStore` (voir [multi-tenancy.md](multi-tenancy.md)).

## Nommage — tout en français (refonte du 2026-09-25)

Tables, colonnes, valeurs d'enum stockées, noms de permissions/rôles, slugs de fonctionnalités, champs d'API et codes d'erreur sont en français. Restent volontairement en anglais :

- **les noms de classes et de méthodes PHP** (`Product`, `SaleService::checkout()`, relations Eloquent `store()`, `items()`...) — convention Laravel ; chaque modèle déclare sa table avec l'attribut `#[Table('...')]` et chaque relation passe sa clé étrangère explicitement ;
- **les noms de paramètres de route** (`{store}`, `{product}`, `{sale}`) — invisibles pour le client, ils conditionnent le scoped route binding (`Store::products()`) ;
- **les colonnes et tables imposées par le framework ou un paquet** : `id`, `created_at`/`updated_at`/`deleted_at`, `password`, `remember_token`, `email_verified_at`, `reference_type`/`reference_id` (morph), les tables `sessions`, `password_reset_tokens`, `cache`, `jobs`, `personal_access_tokens` et celles de spatie/laravel-permission (seule sa clé d'équipe devient `boutique_id`).

Côté API, les horodatages sont exposés en `cree_le`/`modifie_le` (les colonnes restent `created_at`/`updated_at`).

### Tables

| Modèle | Table |
|---|---|
| `User` | `utilisateurs` |
| `Business` / `BusinessUser` | `entreprises` / `utilisateurs_entreprise` |
| `Store` / `StoreUser` | `boutiques` / `utilisateurs_boutique` |
| `BusinessDomain` / `Feature` | `domaines_activite` / `fonctionnalites` |
| `DomainFeature` / `StoreFeatureOverride` / `FeatureDependency` | `fonctionnalites_domaine` / `fonctionnalites_boutique` / `dependances_fonctionnalites` |
| `Plan` / `Subscription` (+ pivot) | `forfaits` / `abonnements` (+ `fonctionnalites_forfait`) |
| `Category` / `Product` / `Service` | `categories` / `produits` / `services` |
| `Customer` | `clients` |
| `Stock` / `StockMovement` | `stocks` / `mouvements_stock` |
| `CashRegister` / `CashRegisterSession` / `CashMovement` | `caisses` / `sessions_caisse` / `mouvements_caisse` |
| `Sale` / `SaleItem` | `ventes` / `lignes_vente` |

### Colonnes principales

| Anglais (ancien) | Français |
|---|---|
| `store_id`, `business_id`, `user_id`, `product_id`, `category_id`, `customer_id` | `boutique_id`, `entreprise_id`, `utilisateur_id`, `produit_id`, `categorie_id`, `client_id` |
| `cash_register_id`, `cash_register_session_id`, `open_session_id`, `sale_id` | `caisse_id`, `session_caisse_id`, `session_ouverte_id`, `vente_id` |
| `owner_user_id`, `invited_by_user_id`, `created_by_user_id`, `opened_by_user_id`, `closed_by_user_id`, `sold_by_user_id` | `proprietaire_id`, `invite_par_id`, `cree_par_id`, `ouverte_par_id`, `fermee_par_id`, `vendeur_id` |
| `name`, `status`, `is_active`, `phone`, `address`, `currency`, `timezone`, `country`, `legal_name`, `settings` | `nom`, `statut`, `actif`, `telephone`, `adresse`, `devise`, `fuseau_horaire`, `pays`, `raison_sociale`, `parametres` |
| `barcode`, `unit`, `purchase_price`, `retail_enabled`/`retail_price`, `wholesale_enabled`/`wholesale_price`, `price`, `duration_minutes` | `code_barres`, `unite`, `prix_achat`, `vente_detail_active`/`prix_detail`, `vente_gros_active`/`prix_gros`, `prix`, `duree_minutes` |
| `quantity`, `minimum_quantity`, `quantity_before`/`quantity_after`, `reason`, `metadata` | `quantite`, `quantite_minimum`, `quantite_avant`/`quantite_apres`, `motif`, `metadonnees` |
| `amount`, `balance_before`/`balance_after`, `opening_amount`, `expected_closing_amount`, `actual_closing_amount`, `difference`, `closing_note`, `opened_at`/`closed_at` | `montant`, `solde_avant`/`solde_apres`, `montant_ouverture`, `montant_fermeture_attendu`, `montant_fermeture_reel`, `ecart`, `note_fermeture`, `ouverte_le`/`fermee_le` |
| `subtotal`, `discount_amount`, `total_amount`, `payment_method`, `idempotency_key`, `sold_at`, `product_name`, `pricing_mode`, `unit_price` | `sous_total`, `montant_remise`, `montant_total`, `mode_paiement`, `cle_idempotence`, `vendue_le`, `nom_produit`, `mode_prix`, `prix_unitaire` |

### Valeurs d'enum stockées

| Enum | Valeurs |
|---|---|
| `UserStatus` / `StoreUserStatus` | `actif`, `inactif` / `invite`, `actif`, `revoque` |
| `BusinessStatus` / `StoreStatus` | `active`, `suspendue` / `active`, `inactive` |
| `BusinessUserRole` | `proprietaire`, `administrateur` |
| `SubscriptionStatus` | `essai`, `actif`, `impaye`, `annule` |
| `PricingMode` / `ProductUnit` | `detail`, `gros` / `piece`, `kg`, `g`, `litre`, `ml`, `boite`, `paquet` |
| `StockMovementType` | `initial`, `achat`, `vente`, `retour`, `ajustement_entree`, `ajustement_sortie`, `inventaire`, `perte` |
| `CashRegisterSessionStatus` / `CashMovementType` | `ouverte`, `fermee` / `ouverture`, `entree`, `sortie`, `ajustement`, `vente`, `remboursement` |
| `SaleStatus` / `PaymentMethod` | `terminee`, `annulee` / `especes`, `mobile_money`, `carte`, `virement`, `mixte` |

Alias du morph map : `produit`, `service`, `vente`. Rôles de boutique : `proprietaire`, `administrateur`, `gerant`, `caissier`, `employe`. Slugs de fonctionnalités : `produits`, `categories`, `services`, `stock`, `ventes`, `caisse`, `clients`, `fournisseurs`, `employes`, `rendez_vous`, `commandes`, `tables`, `rapports`.

**Migrations modifiées sur place** : le projet n'étant pas encore en production, les migrations existantes ont été renommées directement plutôt que complétées par des migrations `renameColumn`. Toute base locale existante doit être recréée (`php artisan migrate:fresh --seed`). Deux index composites portent un nom explicite (`fonctionnalites_domaine_unique`, `dependances_fonctionnalites_unique`) : le nom généré dépassait la limite de 64 caractères de MySQL.

## 0. Conventions de typage (financier et autres)

Ces règles s'appliquent à **toutes** les tables de ce document, sans exception, et priment sur toute mention plus vague plus bas (ex: "prix", "montant") :

| Nature de la donnée | Type SQL | Raison |
|---|---|---|
| Tout montant monétaire (`prix`, `cost_price`, `sous_total`, `total`, `montant`, `opening_balance`, ...) | `DECIMAL(12,2)` (jamais `FLOAT`/`DOUBLE`) | Un flottant binaire ne représente pas exactement des décimales base 10 (ex: `0.1 + 0.2 != 0.3`) ; sur des totaux de caisse cumulés, l'erreur d'arrondi devient un écart de caisse réel. `DECIMAL` est un type exact stocké en base. Ce choix reste valable même pour une devise sans sous-unité (XOF/XAF) — le montant est alors toujours entier avec `.00`. |
| Quantités (`quantite` sur `StockMovement`, `SaleItem`, `OrderItem`) | `DECIMAL(12,3)` | Doit pouvoir représenter une quantité fractionnaire (ex: 1.5 kg de viande), pas seulement des entiers. |
| Taux (`tax_rate`) et pourcentages (remise en %) | `DECIMAL(5,2)` | Suffisant (0.00 à 999.99%) et exact, mêmes raisons que les montants. |
| Statuts (`statut`, `type`) | Colonne `string` avec valeurs contrôlées côté application (PHP `enum` backé), pas de type `ENUM` MySQL natif | Un `enum` PHP (`BackedEnum`) donne l'autocomplétion et la sécurité de type en code ; le type `ENUM` MySQL rend l'ajout d'une valeur coûteux (ALTER TABLE) et n'est pas portable. `string` + validation applicative + cast Eloquent `enum` cumule le meilleur des deux. |
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
- Attributs : `id`, `nom`, `proprietaire_id`, `raison_sociale` (nullable), `pays`, `devise` (ISO 4217, ex: `XOF`), `fuseau_horaire`, `statut` (`actif`, `suspendue`), `created_at`, `updated_at`.
- Relations : `hasMany(Store)`, `hasOne(Subscription)` (abonnement courant), `belongsTo(User, 'proprietaire_id')`.
- Contraintes : `proprietaire_id` obligatoire et référence un `User` existant.
- Index : `proprietaire_id`.
- Règles métier : la suppression d'un Business est un soft delete qui doit cascader (logiquement, pas en SQL `ON DELETE CASCADE`) vers ses Stores pour éviter une boutique orpheline active.

### BusinessUser (ajouté suite à l'audit)
Responsabilité : distinguer les actions **au niveau du Business** (créer une nouvelle boutique, gérer l'abonnement, voir les rapports consolidés) des actions **au niveau d'un Store** (gérées par `StoreUser` + spatie/laravel-permission, voir §3). `spatie/laravel-permission` en mode teams est scopé par `boutique_id` : il ne peut donc pas exprimer nativement "cet utilisateur peut créer une boutique dans ce Business" puisqu'aucune boutique n'existe encore au moment de vérifier ce droit. Voir [permissions.md](permissions.md) §8 pour la justification complète.
- Attributs : `id`, `entreprise_id`, `utilisateur_id`, `role` (`proprietaire`, `administrateur`), `created_at`.
- Relations : `belongsTo(Business)`, `belongsTo(User)`.
- Contraintes : unique (`entreprise_id`, `utilisateur_id`).
- Règles métier : à la création d'un `Business`, une ligne `BusinessUser(role: owner)` est créée automatiquement pour le créateur — `Business.proprietaire_id` reste comme raccourci dénormalisé pratique (ex: affichage rapide, contact facturation) mais **n'est plus l'unique source d'autorisation** ; toute vérification de droit business-level interroge `BusinessUser`, jamais uniquement `proprietaire_id`. Un `role: admin` peut créer des boutiques et voir les rapports consolidés mais pas gérer l'abonnement/facturation (seul `proprietaire` le peut).
- Règles métier : à la création d'un `Store` sous un `Business`, chaque `BusinessUser` de ce `Business` reçoit automatiquement un `StoreUser(status: active)` sur la nouvelle boutique, avec le rôle spatie "Propriétaire" (pour `proprietaire`) ou "Administrateur" (pour `administrateur`) — c'est le mécanisme qui permet nativement à "un utilisateur qui gère plusieurs boutiques" (vision §1 du besoin) d'accéder à toute nouvelle boutique de son Business sans invitation manuelle répétée.

### Store
Responsabilité : une boutique/point de vente concret, unité d'isolation tenant pour toutes les données opérationnelles.
- Attributs : `id`, `entreprise_id`, `domaine_activite_id`, `nom`, `slug` (unique), `adresse`, `telephone`, `devise` (peut différer du Business si multi-pays), `fuseau_horaire`, `statut` (`actif`, `inactive`), `parametres` (JSON — préférences d'affichage, format de reçu, etc.).
- Relations : `belongsTo(Business)`, `belongsTo(BusinessDomain)`, `hasMany(StoreUser)`, `hasMany(StoreFeatureOverride)`, et toutes les entités opérationnelles (`hasMany(Product)`, `hasMany(Sale)`, ...).
- Contraintes : `domaine_activite_id` obligatoire (une boutique doit toujours avoir un domaine métier, potentiellement `autre`).
- Index : `entreprise_id`, `domaine_activite_id`, `slug` (unique).
- Règles métier : `boutique_id` est le "team" spatie/laravel-permission — voir [permissions.md](permissions.md). C'est aussi la valeur portée par `TenantContext` pendant toute la requête.
- **Phase 2** : `domaine_activite_id` est désormais une colonne réelle (migration séparée `add_business_domain_id_to_stores_table`, ajoutée après `domaines_activite` plutôt qu'en modifiant la migration Phase 1 des `boutiques`, comme annoncé). `NOT NULL`, validé actif à la création (`CreateStoreRequest`).

### StoreUser
Responsabilité : la relation d'appartenance d'un `User` à un `Store`, incluant les invitations en attente.
- Attributs : `id`, `boutique_id`, `utilisateur_id`, `statut` (`invite`, `actif`, `revoque`), `invite_par_id` (nullable), `invite_le`, `rejoint_le` (nullable).
- Relations : `belongsTo(Store)`, `belongsTo(User)`.
- Contraintes : unique sur (`boutique_id`, `utilisateur_id`).
- Index : `boutique_id`, `utilisateur_id`, (`boutique_id`,`utilisateur_id`) unique composite.
- Règles métier : c'est l'existence d'un `StoreUser` avec `status = active` qui autorise l'accès à un `Store` — voir [multi-tenancy.md](multi-tenancy.md). Les rôles/permissions de l'utilisateur sur ce store sont gérés séparément par spatie (mode teams), pas par une colonne `role` ici, pour rester extensible.

## 3. Authorization (spatie/laravel-permission, mode teams)

### Role / Permission
Tables fournies par le package (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`), toutes porteuses de `boutique_id` (renommage de `team_id`, voir `config/permission.php`). Pas de migration custom nécessaire au-delà de la migration publiée par le package. Détail complet dans [permissions.md](permissions.md).

## 4. Features (Domain → Feature) — **implémenté en Phase 2**, voir [feature-gate.md](feature-gate.md)

### BusinessDomain
Responsabilité : un type de métier (alimentation générale, boucherie, coiffeur, ...).
- Attributs (implémentés) : `id`, `slug` (unique, ex: `coiffure`), `nom`, `description` (nullable), `icone` (nullable), `actif` (défaut `true`), timestamps.
- Relations : `hasMany(Store)`, `hasMany(DomainFeature)`.
- Index : `slug` (unique).
- Règle métier confirmée en implémentation : `actif` ne gate que la sélection du domaine à la création d'une boutique (`CreateStoreRequest`) — il n'intervient **pas** dans la résolution `FeatureGate` d'une boutique déjà créée. Voir [feature-gate.md](feature-gate.md) §2.

### Feature
Responsabilité : une capacité activable (ex: `produits`, `rendez_vous`, `caisse`).
- Attributs (implémentés) : `id`, `slug` (unique), `nom`, `description` (nullable), `actif` (défaut `true`), timestamps.
- Relations : `hasMany(DomainFeature)`, `hasMany(FeatureDependency, 'fonctionnalite_id')`.
- Index : `slug` (unique).
- Pas de colonne `metadonnees`/`configuration` : rien n'en a eu besoin à ce stade, ajoutée seulement si un cas concret l'exige (éviter l'abstraction spéculative).

### DomainFeature
Responsabilité : la feature est-elle activée **par défaut** pour un domaine donné.
- Attributs : `id` (surrogate, voir note ci-dessous), `domaine_activite_id`, `fonctionnalite_id`, `active_par_defaut` (bool, défaut `true`), timestamps.
- Contraintes : `unique(domaine_activite_id, fonctionnalite_id)` — remplace la clé composite littérale envisagée initialement (le support des clés composites d'Eloquent ne justifie pas la complexité pour une garantie d'unicité identique).

### FeatureDependency (ajouté à l'audit, implémenté en Phase 2)
Responsabilité : `fonctionnalite_id` ne peut être active que si `depend_de_fonctionnalite_id` l'est aussi (voir [features.md](features.md) §7).
- Attributs : `id`, `fonctionnalite_id`, `depend_de_fonctionnalite_id`, timestamps.
- Contraintes : `unique(fonctionnalite_id, depend_de_fonctionnalite_id)`, `depend_de_fonctionnalite_id` en `restrictOnDelete` (on ne supprime pas une feature dont une autre dépend).

### StoreFeatureOverride
Responsabilité : permet à une boutique précise de déroger au défaut de son domaine (activer une feature normalement absente de son métier, ou désactiver une feature normalement présente).
- Attributs : `id`, `boutique_id`, `fonctionnalite_id`, `activee` (pas de défaut — toujours fourni explicitement), timestamps.
- Contraintes : unique (`boutique_id`, `fonctionnalite_id`).
- **Tenant-scopé** : utilise `BelongsToStore` comme tout modèle métier — `boutique_id` toujours forcé serveur, jamais lisible/écrivable inter-store (testé, voir [testing.md](testing.md)).
- Règles métier : la disponibilité effective d'une feature pour un store = `StoreFeatureOverride` si présent, sinon `DomainFeature.active_par_defaut`, à condition que `Feature.actif` soit vrai ET que l'abonnement (`Subscriptions`) l'autorise. Voir [feature-gate.md](feature-gate.md) pour l'algorithme définitif et tranché.

## 5. Catalog

### Sellable (contrat, pas une table)
Interface implémentée par `Product` et `Service` : `getSellablePrice()`, `getSellableLabel()`, `isTaxable()`, `getStoreId()`, **`tracksStock(): bool`** (ajouté suite à l'audit — retourne `$this->track_stock` sur `Product`, `false` en dur sur `Service`). Permet à `Sales`/`Inventory` de décider d'écrire un `StockMovement` sans jamais faire `if ($sellable instanceof Product)` — exactement le genre de branchement conditionnel par type que le projet cherche à éviter, ici appliqué à la vente plutôt qu'au domaine métier. Voir §10.

**Point d'audit** : `SaleItem.sellable_type`/`OrderItem.sellable_type` doivent être enregistrés via un morph map explicite (`Relation::enforceMorphMap(['produit' => Product::class, 'service' => Service::class])`, déclaré dans le `ServiceProvider` du module `Catalog`) plutôt que le nom de classe PHP complet. Sans cela, un renommage ou un déplacement futur de `App\Modules\Catalog\Models\Product` casserait silencieusement toutes les lignes historiques déjà écrites en base.

### Product
- Attributs : `id`, `boutique_id`, `product_category_id` (nullable), `nom`, `sku` (nullable, unique par store), `code_barres` (nullable), `prix`, `cost_price` (nullable), `is_taxable`, `tax_rate` (nullable), `unite` (ex: `kg`, `pièce`), `track_stock` (bool — un produit peut exister sans suivi de stock, ex: article divers), `actif`.
- Relations : `belongsTo(ProductCategory)`, `hasMany(StockMovement)`, `morphMany(SaleItem, 'sellable')`.
- Index : `boutique_id`, (`boutique_id`,`sku`) unique, `code_barres`.
- Règles métier : si `track_stock = true`, toute vente doit vérifier la disponibilité (sauf configuration "vente en négatif" autorisée par la boutique).

### ProductCategory
- Attributs : `id`, `boutique_id`, `nom`, `parent_id` (nullable, catégories imbriquées).
- Index : `boutique_id`, `parent_id`.

### Service
- Attributs : `id`, `boutique_id`, `service_category_id` (nullable), `nom`, `prix`, `duree_minutes` (utilisé par `Appointments`), `is_taxable`, `tax_rate`, `actif`.
- Relations : `belongsTo(ServiceCategory)`, `morphMany(SaleItem, 'sellable')`, `hasMany(Appointment)`.
- Index : `boutique_id`.

### ServiceCategory
Symétrique à `ProductCategory`.

## 6. Inventory

> **Révision (Phase 4.1, 2026-09-24)** : le prompt de cette phase a demandé explicitement un modèle `Stock` séparé plutôt que la colonne dénormalisée `produits.current_stock` décrite plus bas dans cette section et en §11. Décision actée avec l'utilisateur : suivre la nouvelle demande. Ce qui suit est **remplacé** par la structure implémentée, détaillée dans [inventory.md](inventory.md) — cette section garde le texte d'origine barré-en-substance (plutôt que supprimé) pour que la trace de la décision reste lisible.
>
> **Implémenté à la place** : `Stock` (`id`, `boutique_id`, `produit_id`, `quantite` `DECIMAL(12,3)`, `quantite_minimum` `DECIMAL(12,3)` nullable ; unique `(boutique_id, produit_id)`) porte l'état courant. `StockMovement` reste le ledger append-only mais référence `stock_id` en plus de `produit_id`, et n'a pas de `unit_cost` (reporté — aucun module Achats/Suppliers ne l'utilise encore). Les types retenus : `initial`, `achat`, `vente`, `retour`, `ajustement_entree`, `ajustement_sortie`, `inventaire`, `perte` — pas de `transfer_in`/`transfer_out` (non demandés par cette phase, restent une conception documentée mais non implémentée). Voir [inventory.md](inventory.md) pour le détail complet (concurrence, immutabilité, permissions, contrat futur avec Sales).

### StockMovement (conception d'origine, remplacée ci-dessus)
Responsabilité : ledger append-only de tout changement de quantité. **Il n'existe pas de colonne "stock actuel" éditée directement** ; voir §11.
- Attributs : `id`, `boutique_id`, `produit_id`, `type` (`achat`, `vente`, `perte`, `ajustement`, `retour`, `inventaire`, `transfer_in`, `transfer_out`, `initial`), `quantite` (signé : positif = entrée, négatif = sortie), `unit_cost` (nullable, utile pour le coût moyen pondéré), `reference_type`/`reference_id` (morph, pointe vers `Sale`, `SaleItem`, un futur `PurchaseOrder`, ou rien pour un ajustement manuel), `note` (nullable), `cree_par_id`.
- Relations : `belongsTo(Product)`, `morphTo(reference)`.
- Index : `boutique_id`, `produit_id`, (`produit_id`,`created_at`) pour le calcul de solde par période, `reference_type`+`reference_id`.
- Règles métier : jamais d'`UPDATE` sur une ligne existante ; une correction s'exprime comme un nouveau mouvement de type `ajustement`. `retour` (retour client, distinct de `ajustement` pour du reporting propre : "combien de perte" vs "combien de retour") et `inventaire` (écart constaté lors d'un inventaire physique, distinct d'un `ajustement` ad hoc pour pouvoir dater/grouper une campagne d'inventaire) sont des types dédiés ajoutés suite à l'audit — demande explicite du besoin métier ("retours", "inventaires"). `transfer_out`/`transfer_in` sont la **seule** exception délibérée au principe d'isolation stricte par store : un transfert crée un mouvement `transfer_out` sur le store source et `transfer_in` sur le store destination, autorisé uniquement si les deux stores appartiennent au même `entreprise_id`, derrière une permission dédiée `stock.transfer` vérifiée sur le store source, et journalisé (voir [multi-tenancy.md](multi-tenancy.md) §7 "Exception contrôlée : transferts inter-boutiques"). Le solde courant = `SUM(quantity)` pour le produit, mis en cache dans une colonne dénormalisée `produits.current_stock` recalculée à l'écriture (voir §11) pour éviter un `SUM` à chaque lecture de liste produits.

## 7. Sales

> **Révision (Phase 4.3, 2026-09-26)** : même décision qu'en Phase 4.1/4.2 — le prompt de cette phase liste explicitement `SaleItem.produit_id` (pas de morph `sellable_type`/`sellable_id`). `cle_idempotence` était déjà anticipé ici avant même son implémentation ; aucun écart sur ce point.
>
> **Implémenté à la place** : `SaleItem` référence `produit_id` directement (`Product` uniquement — `mode_prix`/détail-gros n'a de sens que pour un `Product`, jamais pour un `Service`). Champs renommés vers la nomenclature du prompt : `montant_remise`/`montant_total` (pas `discount_total`/`tax_total`/`total` — pas de fiscalité, cohérent avec l'absence de taxe sur `Product`), `nom_produit`/`prix_unitaire` (pas `label_snapshot`/`unit_price_snapshot` — le nom exprime déjà qu'il s'agit d'un instantané, documenté dans le modèle plutôt que dans le nom de colonne). Pas de `SalePayment` : un seul `mode_paiement` sur `Sale` (`especes` uniquement utilisable cette phase), le paiement mixte multi-lignes reste une conception future si un vrai module Payments est construit. Voir [sales.md](sales.md) pour le détail complet (Checkout, verrouillage, idempotence, intégrations Inventory/CashRegister).

### Sale (conception d'origine, remplacée ci-dessus)
- Attributs : `id`, `boutique_id`, `client_id` (nullable — vente au comptoir sans client identifié), `session_caisse_id`, `vendeur_id`, `statut` (`terminee`, `annulee`, `refunded`, `partially_refunded`), `sous_total`, `discount_total`, `tax_total`, `total`, `cle_idempotence` (nullable, généré par le client mobile), `vendue_le`.
- Relations : `hasMany(SaleItem)`, `hasMany(SalePayment)`, `belongsTo(Customer)`, `belongsTo(CashRegisterSession)`.
- Index : `boutique_id`, `client_id`, `vendue_le`, `statut`, (`boutique_id`,`cle_idempotence`) unique.
- Règles métier : `total` est toujours dérivé de `SaleItem` + remises + taxes, jamais saisi manuellement (recalcul serveur systématique, jamais fait confiance à un total envoyé par le client mobile). `cle_idempotence` (ajouté suite à l'audit) : un client mobile sur réseau instable peut soumettre deux fois la même requête de création de vente (double tap, retry automatique) ; le client génère un UUID par tentative de vente et le backend retourne la `Sale` déjà créée (au lieu d'en recréer une seconde) si la même paire (`boutique_id`, `cle_idempotence`) existe déjà — évite une double vente et un double décrément de stock.

### SaleItem (conception d'origine, remplacée ci-dessus)
- Attributs : `id`, `vente_id`, `sellable_type`, `sellable_id` (morph vers `Product` ou `Service`), `label_snapshot` (nom au moment de la vente, car un produit peut être renommé/supprimé après coup), `quantite`, `unit_price_snapshot`, `montant_remise`, `tax_amount`, `line_total`.
- Règles métier : capture un instantané (`_snapshot`) du prix et du libellé pour que l'historique de vente reste correct même si le produit change de prix ou est supprimé plus tard (jamais de suppression physique d'un `Product` référencé — soft delete uniquement).

### SalePayment
- Attributs : `id`, `vente_id`, `method` (`especes`, `mobile_money`, `carte`, `virement`, `autre`), `montant`, `reference` (nullable, ex: id de transaction mobile money), `paid_at`.
- Règles métier : `SUM(SalePayment.amount) = Sale.total` requis pour qu'une vente passe en `terminee` ; permet nativement le paiement mixte (plusieurs lignes de paiement pour une même vente).

## 8. CashRegister

> **Révision (Phase 4.2, 2026-09-25)** : le prompt de cette phase a demandé explicitement une entité `CashRegister` séparée (une boutique peut avoir plusieurs caisses physiques) là où la conception d'origine ci-dessous scopait `CashRegisterSession` directement au `boutique_id`, sous-entendant une seule caisse par boutique. Même décision qu'en Phase 4.1 (Inventory, voir §6) : la demande explicite de la phase l'emporte, documentée ici plutôt que silencieusement contredite.
>
> **Implémenté à la place** : `CashRegister` (`id`, `boutique_id`, `nom`, `code` nullable unique par store, `actif`, `session_ouverte_id` — pointeur applicatif, pas de FK, vers la session actuellement ouverte) porte l'identité de la caisse. `CashRegisterSession` reste l'historique d'utilisation mais référence `caisse_id` en plus de `boutique_id`, et les noms de champs suivent la nomenclature du prompt (`montant_ouverture`/`montant_fermeture_attendu`/`montant_fermeture_reel`/`ecart`/`note_fermeture`, pas `*_balance`). `CashTransaction` est remplacé par `CashMovement`, types `ouverture`/`entree`/`sortie`/`ajustement`/`vente`/`remboursement` (pas `sale_in`/`refund_out`/`expense_out`/`deposit_in`/`withdrawal_out`). Voir [cash-register.md](cash-register.md) pour le détail complet (une seule session ouverte, concurrence, permissions, contrat futur avec Sales).

### CashRegisterSession (conception d'origine, remplacée ci-dessus)
- Attributs : `id`, `boutique_id`, `ouverte_par_id`, `fermee_par_id` (nullable), `opening_balance`, `expected_closing_balance` (calculé), `actual_closing_balance` (nullable, saisi à la fermeture), `statut` (`ouverte`, `fermee`), `ouverte_le`, `fermee_le` (nullable).
- Règles métier : une seule session `ouverte` à la fois par store (contrainte applicative, pas SQL). L'écart `actual - expected` à la fermeture est un signal à faire remonter dans `Reports`.

### CashTransaction (conception d'origine, remplacée ci-dessus)
- Attributs : `id`, `boutique_id`, `session_caisse_id`, `type` (`sale_in`, `refund_out`, `expense_out`, `deposit_in`, `withdrawal_out`), `montant`, `reference_type`/`reference_id` (morph vers `Sale` si applicable), `note`, `cree_par_id`.
- Index : `session_caisse_id`, `boutique_id`.

## 9. Customers, Suppliers, Employees

### Customer
- Attributs : `id`, `boutique_id`, `first_name`, `last_name`, `telephone` (nullable), `email` (nullable), `notes`, `loyalty_points` (nullable, extensible).
- Index : `boutique_id`, `telephone`.

### Supplier
- Attributs : `id`, `boutique_id`, `nom`, `telephone`, `email`, `notes`.
- Relations : référencé par `StockMovement.reference` (type `achat`) — pas de FK directe pour rester découplé ; à réévaluer si un module "Achats/PurchaseOrder" apparaît dans la roadmap.

### Employee
- Attributs : `id`, `boutique_id`, `store_user_id` (nullable — lien optionnel vers un compte applicatif), `first_name`, `last_name`, `position`, `telephone`, `hired_at`, `statut` (`actif`, `inactive`).
- Index : `boutique_id`, `store_user_id`.

## 10. Produits vs Services — décision d'architecture

**Problème** : certains métiers vendent des biens physiques (stock, code-barres), d'autres des prestations (durée, disponibilité), d'autres les deux (restaurant : plat = produit sans stock fin réel mais avec ingrédients, coiffeur : produit capillaire + service coupe).

| Option | Description | Avantages | Inconvénients |
|---|---|---|---|
| A. Table unique polymorphique `catalog_items` avec `type` + colonnes nullable | Un seul modèle, `type` discrimine | Une seule table à interroger pour "tout ce qui est vendable" | Colonnes nullable qui ne s'appliquent qu'à un type (`duree_minutes` n'a pas de sens pour un produit), requêtes et validations pleines de conditions selon `type` |
| B. Deux modèles distincts (`Product`, `Service`) + table pivot pour les ventes mixtes | Chaque modèle a exactement ses colonnes | Modèles propres, migrations propres, chacun évolue indépendamment (le stock n'a de sens que pour Product) | `SaleItem` doit référencer "l'un ou l'autre" |
| **C. Deux modèles distincts + contrat `Sellable` + relation polymorphique côté `SaleItem` (retenu)** | Comme B, mais `SaleItem.sellable_type/sellable_id` unifie la référence sans table pivot | Cumule la propreté de B et la simplicité de requête de A pour le seul endroit qui a réellement besoin de traiter les deux de façon uniforme (la vente) | Une relation polymorphique de plus dans le schéma (coût mineur, bien maîtrisé par Eloquent) |

**Décision : option C.** Elle évite la complexité inutile d'une table fourre-tout (rejetée explicitement par la demande du projet) tout en donnant à `Sales`/`Orders` un point d'intégration unique. Un "produit/service combiné" (ex: forfait coiffure + vente de shampoing) se modélise simplement comme deux `SaleItem` dans la même `Sale`, pas comme une nouvelle entité.

## 11. Stock — décision d'architecture

> **Révision (Phase 4.1, 2026-09-24)** : voir la note en tête de §6. Le principe ci-dessous ("jamais de mutation directe, toujours un mouvement qui la justifie, jamais de `SUM()` à la lecture") reste intégralement vrai et appliqué — seul le support physique du cache change : un modèle `Stock` séparé plutôt qu'une colonne sur `produits`. Le verrou de concurrence (§ci-dessous) porte donc sur la ligne `Stock`, pas sur la ligne `Product`. Voir [inventory.md](inventory.md) §7 pour le code réellement implémenté.

**Rejeté explicitement** : `product.stock = product.stock - 1` (aucune traçabilité, aucune possibilité d'audit ou de correction).

**Retenu à l'origine** : `StockMovement` en ledger append-only (§6) + colonne dénormalisée `produits.current_stock` recalculée **à l'écriture** de chaque mouvement (dans la même transaction), pour que la lecture (liste produits, vérification de disponibilité avant vente) reste une simple lecture de colonne et non un `SUM()` sur potentiellement des milliers de mouvements. Le mouvement reste la source de vérité ; la colonne dénormalisée est un cache reconstructible (commande artisan de recalcul à prévoir pour la roadmap, en cas de divergence détectée). **Remplacé en Phase 4.1** par un modèle `Stock` séparé portant ce même cache (voir la révision ci-dessus) — le raisonnement sur le "pourquoi un cache plutôt qu'un `SUM()`" reste identique, seul l'emplacement du cache change.

Cela couvre nativement tous les cas cités dans le besoin (achat +100, vente -5, perte -2, réappro +20, correction -1, retour, inventaire) comme des lignes du même mécanisme, sans entités séparées `StockEntry`/`StockExit`/`Adjustment` qui dupliqueraient la même structure sous des noms différents — un `type` suffit et simplifie les requêtes de reporting ("tous les mouvements de ce produit, quel que soit leur type").

**Concurrence (point d'audit, verrou déplacé sur `Stock` en Phase 4.1)** : deux ventes simultanées sur le même produit peuvent chacune lire `quantity = 5` et accepter chacune une vente de 3, aboutissant à un stock réel de -1 alors que chaque vérification individuelle semblait correcte (race condition classique "lire-puis-écrire"). Parade obligatoire, appliquée dans `InventoryService` (voir [inventory.md](inventory.md) §7) : verrouiller la ligne `Stock` en lecture avant de vérifier la disponibilité, à l'intérieur de la même transaction qui insère le mouvement et met à jour `quantite` — code réel :

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

Le verrou (`lockForUpdate`) sérialise les écritures concurrentes sur la même ligne `Product` — la seconde transaction attend que la première commit avant de lire à son tour un `current_stock` à jour. Recommandation complémentaire : une commande artisan planifiée de réconciliation (`SUM(mouvements_stock.quantity)` vs `produits.current_stock`) pour détecter toute divergence malgré cette protection (bug futur, opération hors service) — voir [risks.md](risks.md).

## 12. Appointments

### Appointment
- Attributs : `id`, `boutique_id`, `service_id`, `employee_id` (nullable si non assigné), `client_id`, `starts_at`, `ends_at`, `statut` (`booked`, `confirmed`, `terminee`, `annulee`, `no_show`), `notes`.
- Index : `boutique_id`, `employee_id`+`starts_at` (recherche de disponibilité), `client_id`.
- Règles métier : `ends_at` dérivé de `starts_at + service.duree_minutes` sauf ajustement manuel ; un chevauchement pour le même `employee_id` doit être rejeté en validation applicative (pas de contrainte SQL d'exclusion portable simplement sous MySQL — vérification en transaction avec verrou).

## 13. Orders

### Table (ajouté suite à l'audit)
Responsabilité : représente une table physique d'un restaurant (ou un poste de service équivalent), pour permettre une vue "plan de salle" (quelles tables sont libres/occupées) sans avoir à parcourir les `Order` ouvertes. Absent de la conception initiale, où seul un champ texte `table_number` existait sur `Order` — insuffisant pour répondre à l'exemple du besoin ("Restaurant → ... tables ...", `tables` cité comme feature à part entière au même titre que `commandes`).
- Attributs : `id`, `boutique_id`, `label` (ex: "Table 4", "Comptoir 2"), `capacity` (nullable), `statut` (`free`, `occupied`, `reserved`, `out_of_service`).
- Relations : `hasMany(Order)`.
- Index : `boutique_id`.
- Règles métier : `statut` est dérivé/synchronisé par le service `Orders` à l'ouverture/fermeture d'une commande liée (pas de double source de vérité manuelle) ; reste une entité du module `Orders` (pas un module séparé) car elle n'a de sens que pour les domaines qui activent la feature `tables`, elle-même dépendante de `commandes` (voir [features.md](features.md) §7 "Dépendances entre features").

### Order / OrderItem
- `Order` : `id`, `boutique_id`, `client_id` (nullable), `table_id` (nullable, FK vers `Table` — remplace le `table_number` texte initial), `type` (`dine_in`, `delivery`, `pickup`), `statut` (`pending`, `preparing`, `ready`, `served`, `terminee`, `annulee`), `vente_id` (nullable, renseigné à la finalisation).
- `OrderItem` : `id`, `order_id`, `sellable_type`/`sellable_id` (même contrat `Sellable` que `SaleItem`), `quantite`, `unit_price_snapshot`, `notes` (ex: "sans oignons").
- Règles métier : à la finalisation, `Orders` crée une `Sale` + ses `SaleItem` à partir des `OrderItem`, puis renseigne `Order.vente_id` — pas de duplication de logique de calcul de prix (réutilise le service de `Sales`). Un domaine `delivery`/`pickup` pur (pas de salle physique) n'active pas la feature `tables` et laisse `table_id` toujours `null`.

## 14. Subscriptions — **version minimale implémentée en Phase 2**

Voir [subscriptions.md](subscriptions.md) pour le détail complet de `Plan`, `Subscription`, et la relation avec les limites/features.

- `forfaits` : `id`, `code` (unique), `nom`, `prix_mensuel` (`DECIMAL(12,2)`, défaut 0 — champ posé mais aucun paiement réel), `prix_annuel` (nullable), `max_boutiques`/`max_utilisateurs_par_boutique`/`max_produits_par_boutique` (nullable = illimité), `actif`.
- `abonnements` : `id`, `entreprise_id` (**unique** — un seul abonnement actif par Business), `forfait_id`, `statut` (`trialing`/`actif`/`past_due`/`annulee`), `fin_essai_le`, `debut_periode_le`, `fin_periode_le`, `annule_le`.
- `fonctionnalites_forfait` : pivot pur (`forfait_id`, `fonctionnalite_id`, clé primaire composite) — pas de modèle Eloquent dédié, `Plan::features()` en `belongsToMany` standard, aucune donnée pivot supplémentaire à porter (contrairement à `DomainFeature`).
- Un `Business` créé en Phase 1 n'a **aucune** `Subscription` par défaut — voir [feature-gate.md](feature-gate.md) §4 pour la conséquence directe sur `FeatureGate` (fail-open, pas fail-closed).

## 15. Notifications

### Notification (log)
- Attributs : `id`, `utilisateur_id`, `boutique_id` (nullable, certaines notifications sont plateforme), `type`, `channel` (`mail`, `push`, `sms`), `payload` (JSON), `read_at` (nullable), `sent_at`.
- Utilise la table `notifications` native de Laravel si le canal `database` est utilisé, ou une table dédiée si on a besoin de champs supplémentaires — décision à prendre au moment de l'implémentation selon les canaux réellement utilisés (hors périmètre de cette phase de conception).

## Ce qui n'est délibérément pas encore figé

Le nombre exact de colonnes JSON de configuration (`parametres`), la stratégie de devise multi-pays au sein d'un même `Business`, et le détail des `PlanLimit` (voir [subscriptions.md](subscriptions.md)) seront affinés à l'implémentation de chaque module, sans remettre en cause les relations structurantes décrites ici.
