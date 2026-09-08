# Catalog (Phase 3)

Module `app/Modules/Catalog/`. Le socle générique de ce qu'une boutique vend, valable pour tout métier — voir `docs/database.md` §5/§10 pour la décision d'architecture d'origine, confirmée et implémentée ici sans modification.

## 1. Category, Product, Service

Trois modèles, tenant-scopés (`BelongsToStore`), soft-deletable :

| Modèle | Rôle | Champs propres |
|---|---|---|
| `Category` | **Partagée** entre Product et Service — un seul arbre de catégories par boutique, pas un par type de catalogue | `name`, `slug` (unique par store), `description`, `is_active` |
| `Product` | Bien physique | `sku`/`barcode` (nullable, uniques par store), `unit` (enum `ProductUnit`), `purchase_price`/`selling_price` (`DECIMAL(12,2)`) |
| `Service` | Prestation | `price` (`DECIMAL(12,2)`), `duration_minutes` (nullable) |

**Écart assumé vs la conception d'origine** (`database.md` avant cette phase) : pas de `is_taxable`/`tax_rate` sur Product/Service — la consigne de cette phase donne une liste d'attributs minimale qui ne les inclut pas, et rien à ce stade (pas de module Sales) n'en a besoin. Ajouté seulement quand un cas concret l'exigera, pas par anticipation. `ProductCategory`/`ServiceCategory` séparées (envisagées dans la conception d'origine) sont fusionnées en une seule `Category`, conformément à la consigne explicite de cette phase.

## 2. Sellable

```php
interface Sellable
{
    public function getSellableLabel(): string;
    public function getSellablePrice(): string; // decimal string, jamais un float
    public function tracksStock(): bool;
}
```

Implémentée par `Product` (`tracksStock() = true`) et `Service` (`tracksStock() = false`). Placée dans `app/Shared/Contracts/` (pas dans `Modules/Catalog/`), conformément à `docs/modules.md` : "Le contrat `Sellable` reste la bonne solution quand un module doit traiter plusieurs types d'un autre module de façon polymorphique."

**Ce qui n'est PAS fait maintenant, volontairement** : aucune relation polymorphique (`morphTo`) n'existe encore — elle apparaîtra sur `SaleItem`/`OrderItem` en Phase 4/5, quand ces tables existeront. `Sellable` est prête à être consommée à ce moment sans modification. Le morph map (`'product' => Product::class`, `'service' => Service::class`) est déjà enregistré dans `CatalogServiceProvider::boot()`, avant qu'aucune ligne polymorphique existe — pour qu'aucune donnée historique ne soit jamais écrite avec un nom de classe complet.

**Pourquoi une interface plutôt qu'une table unique polymorphique** : cf. `database.md` §10 — évite les colonnes nullable qui ne s'appliquent qu'à un type, tout en donnant à un futur `SaleItem` un point d'intégration unique. Pas d'over-engineering : pas de classe abstraite, pas de trait partagé forcé, juste une interface à deux méthodes + un booléen.

## 3. Isolation multi-tenant

Trois couches, comme partout ailleurs dans le projet :

1. **`BelongsToStore`** (Couche 2) sur les trois modèles — `store_id` toujours forcé depuis `TenantContext`, jamais depuis le payload client, immuable après création. Aucune nouveauté : réutilise le mécanisme déjà audité en Phase 1.
2. **Scoped route model binding** (Couche 6) — `Route::scopeBindings()` sur tout le groupe `stores/{store}/...` du module. `Category`, `Product`, `Service` exposent chacune une relation `store(): BelongsTo` : c'est ce qui permet à Laravel de vérifier, **au niveau du routage, avant tout code applicatif**, qu'un `{product}` référencé sous `/stores/{storeA}/products/{id}` appartient bien à `storeA` — sinon 404 immédiat. C'est la **première utilisation réelle** de ce mécanisme dans le projet (documenté dès l'audit, jamais consommé avant faute d'endpoint qui en avait besoin).
3. **Policies** (`CategoryPolicy`, `ProductPolicy`, `ServicePolicy`) — filet de sécurité en cas de défaillance de la couche 2, vérifient explicitement `$model->store_id === $store->id` en plus de la permission spatie. Voir §5.

**Validation tenant-aware (Couche 5)** : `category_id` fourni à la création/modification d'un Product/Service est validé par `TenantScopedRules::existsInCurrentStore('categories')` — **premier consommateur réel** de ce mécanisme construit (et seulement testé en isolation) en Phase 1. `sku`/`barcode`/`slug` utilisent `TenantScopedRules::uniqueInCurrentStore()` (nouvelle méthode ajoutée cette phase), scopés par store et non globalement.

## 4. Authorization vs FeatureGate — ne pas confondre

| Mécanisme | Répond à | Implémenté par |
|---|---|---|
| **FeatureGate** | "Cette boutique a-t-elle la capacité `products` ?" | Middleware `feature:products`/`feature:categories`/`feature:services`, avant tout le reste |
| **Authorization** | "Cet utilisateur a-t-il `products.create` ?" | Policy (`$user->can(...)` à l'intérieur), après FeatureGate |

Les features consommées (`categories`, `products`, `services`) **existaient déjà**, seedées en Phase 2 (`FeatureSeeder`) — aucune nouvelle Feature créée cette phase, conformément à la nomenclature déjà en place (pas de préfixe `catalog.` inventé). Une boutique `hair_salon` (qui n'active ni `products` ni `categories` par défaut) reçoit un `403 FEATURE_DISABLED` sur ces routes, **même pour son propriétaire** qui a pourtant `products.create` — testé explicitement (`CatalogFeatureGateTest`), preuve que les deux mécanismes restent indépendants.

## 5. Permissions ajoutées

`categories.{view,create,update,delete}`, `products.{view,create,update,delete}`, `services.{view,create,update,delete}` — ajoutées à `App\Modules\Authorization\Support\Permissions` et au mapping `StoreRole::defaultPermissions()` :

| Rôle | Accès catalogue |
|---|---|
| owner / admin / manager | CRUD complet |
| cashier / employee | lecture seule |

Manager reçoit l'accès complet conformément à `docs/permissions.md` §3, qui nomme déjà explicitement "produits" dans son périmètre de "gestion quotidienne".

## 6. Écart de conception assumé : Policy plutôt que middleware `permission:*` seul

`StoreMemberController` (Phase 1) utilisait uniquement le middleware `permission:store_users.manage`, sans Policy. Pour Catalog, une Policy est utilisée pour **chaque** action (y compris `viewAny`/`create`, sans instance), combinant le contrôle "bonne boutique" et "bonne permission" en un seul endroit — conforme à la règle de sécurité déjà actée à l'audit ("aucune route retournant une ressource par ID sans Policy associée"). Différence mineure et assumée avec le précédent de Phase 1 ; non rétro-appliquée à `StoreMemberController` (hors périmètre de cette phase), à harmoniser dans un futur nettoyage si jugé utile.

## 7. API

```
GET/POST    /api/stores/{store}/categories[/{category}]
GET/POST    /api/stores/{store}/products[/{product}]
GET/POST    /api/stores/{store}/services[/{service}]
PUT/DELETE  ... /{category|product|service}
```

Pagination : `?per_page=` (défaut 20, plafonné à 100). Filtres : `search` (nom), `is_active`, `category_id` (Product/Service uniquement). Enveloppe JSON standard (`ApiResponse`), `ProductResource`/`ServiceResource` chargent `category` uniquement si explicitement demandé (`whenLoaded`), jamais de colonnes internes (`deleted_at` notamment) exposées.

## 8. Ce qui est volontairement laissé à Inventory / Sales

- Aucune colonne de stock/quantité sur `Product` — `tracksStock(): bool` existe précisément pour que la future Inventory sache QUOI suivre, sans que Catalog sache COMMENT.
- Aucune relation polymorphique construite — attend `SaleItem`/`OrderItem`.
- Aucune notion de panier, prix promotionnel, taxe : hors périmètre, non anticipé.
