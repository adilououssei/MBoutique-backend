# Catalog (Phase 3 — révision « Catalog avancé »)

Module `app/Modules/Catalog/`. Le socle générique de ce qu'une boutique vend, valable pour tout métier — voir `docs/database.md` §5/§10 pour la décision d'architecture d'origine.

> **Révision (2026-09-23)** : cette version remplace la Phase 3 initiale (`selling_price` unique) par une tarification détail/gros sur `Product`, ajoute l'import Excel et prépare l'architecture de création vocale, conformément au nouveau prompt de phase qui a explicitement remplacé l'ancien. `Category` et `Service` sont inchangés par rapport à la version précédente.

## 1. Category

Partagée entre `Product` et `Service` — un seul arbre de catégories par boutique, pas un par type de catalogue. Champs : `nom`, `slug` (unique par store), `description`, `actif`. Tenant-scopée (`BelongsToStore`), soft-deletable.

## 2. Product

Bien physique. Champs propres :

| Champ | Type | Règle |
|---|---|---|
| `sku` / `code_barres` | `string` nullable | uniques par store |
| `unite` | enum `ProductUnit` (`piece`, `kg`, `g`, `litre`, `ml`, `boite`, `paquet`) | unité, indépendante du mode de vente — voir §5 |
| `prix_achat` | `DECIMAL(12,2)` nullable | prix d'achat |
| `vente_detail_active` / `prix_detail` | `bool` / `DECIMAL(12,2)` nullable | voir §5 |
| `vente_gros_active` / `prix_gros` | `bool` / `DECIMAL(12,2)` nullable | voir §5 |

`selling_price` (version précédente) a été **retiré**, pas ajouté à côté — un seul prix par mode, pas de troisième prix générique qui ferait doublon.

## 3. Service

Inchangé : `prix` (`DECIMAL(12,2)`), `duree_minutes` nullable, pas de détail/gros (une prestation n'a qu'un prix).

## 4. Tarification Détail / Gros (Product uniquement)

Un produit peut être vendu en détail uniquement, en gros uniquement, ou les deux — jamais l'inverse d'« aucun des deux avec un prix quand même ». Invariant appliqué à deux niveaux :

1. **`App\Modules\Catalog\Support\ProductRules`** (Couche validation, `prix_detail`/`prix_gros` : `required_if:*_enabled,true` + `prohibited_unless:*_enabled,true`, toutes deux des règles implicites donc évaluées même sur un payload partiel où le champ est totalement absent).
2. **`Product::booted()`** (`static::saving`) : si `*_enabled` est `false`, force `*_price` à `null` avant l'écriture, quel que soit le point d'entrée (manuel, import, vocal futur, ou un appel Eloquent direct dans un test). Nécessaire parce qu'une mise à jour partielle peut désactiver `vente_detail_active` sans jamais toucher `prix_detail` dans le payload — la validation seule ne suffit pas à garantir l'état **stocké**.

`unite` (l'unité physique) et `vente_detail_active`/`vente_gros_active` (le mode commercial) sont deux concepts indépendants, jamais fusionnés.

## 5. Sellable

```php
interface Sellable
{
    public function getSellableLabel(): string;
    public function getSellablePrice(): string; // prix d'affichage générique, jamais un float
    public function tracksStock(): bool;
}
```

Implémentée par `Product` et `Service`, placée dans `app/Shared/Contracts/`. `Product::getSellablePrice()` retourne `prix_detail ?? prix_gros` — un prix d'affichage générique (listes, recherche), **pas** ce qu'un futur Sales doit utiliser pour calculer une vente : voir §9 pour le contrat réel. Aucune relation polymorphique (`morphTo`) n'existe encore ; le morph map (`'produit' => Product::class`, `'service' => Service::class`) est déjà enregistré dans `CatalogServiceProvider::boot()`.

## 6. Contrat commun de création

Les trois méthodes (manuelle, Excel, vocale) convergent vers la **même** logique, pas trois services parallèles :

```
Manuel (CreateProductRequest)  ─┐
Excel (ProductImportService)   ─┼─▶ App\Modules\Catalog\Support\ProductRules::rules()
Vocal (VoiceProductParser, futur) ─┘         │
                                              ▼
                                   Validator::make(...)
                                              │
                                              ▼
                                   App\Modules\Catalog\Services\ProductService
                                              │
                                              ▼
                                           Product
```

`ProductRules::rules(mixed $ignore = null, bool $partial = false)` est la seule source de vérité des règles (noms, unicité par store via `TenantScopedRules`, contrainte détail/gros). `CreateProductRequest`/`UpdateProductRequest` l'appellent directement ; `ProductImportService` construit un tableau normalisé par ligne puis appelle `Validator::make($row, ProductRules::rules())` — mêmes règles, aucune validation "allégée" pour l'import (voir §7). `ProductService::create()`/`update()` est le seul point d'écriture en base ; aucun des trois chemins n'appelle `Product::create()` directement.

## 7. Création manuelle

`POST /api/boutiques/{store}/produits` avec `CreateProductRequest` (slug auto-dérivé du nom si absent). `PUT /api/boutiques/{store}/produits/{product}` avec `UpdateProductRequest` (payload partiel, `sometimes` partout).

## 8. Import Excel

Dépendance ajoutée (approuvée) : `maatwebsite/excel` ^4.0 — aucune librairie Excel n'existait dans le projet, et écrire un parseur XLSX à la main aurait été une réinvention coûteuse pour un besoin standard.

- `POST /api/boutiques/{store}/produits/importer` — `multipart/form-data`, champ `fichier`. `ImportProductsRequest` vérifie `mimes:xlsx,xls` (contenu réel via fileinfo, pas seulement l'extension) et `max:5120` (5 Mo).
- `GET /api/boutiques/{store}/produits/importer/modele` — télécharge un `.xlsx` (`ProductImportTemplateExport`) avec les colonnes attendues et une ligne d'exemple.

**Colonnes du modèle** : `nom`, `categorie`, `description`, `sku`, `code_barres`, `unite`, `prix_achat`, `vente_detail_active`, `prix_detail`, `vente_gros_active`, `prix_gros`, `actif`. Les en-têtes sont lus via `WithHeadingRow` (normalisation `snake_case` automatique), donc robustes à la casse/espacement, mais les noms de colonnes eux-mêmes doivent correspondre.

**Résolution de `categorie` par nom** (§ligne) : recherche `Category::where('boutique_id', $store->id)->whereRaw('LOWER(name) = ?', ...)` — **jamais** globale. Catégorie absente → **ligne rejetée** avec une erreur explicite (`"La catégorie \"X\" est introuvable dans cette boutique."`), jamais créée automatiquement.

**Stratégie transactionnelle (décision documentée)** : *pas* une transaction unique englobant tout le fichier. Chaque ligne valide est créée (et commitée) dès sa validation ; une ligne invalide est ajoutée au rapport et n'interrompt pas le traitement des suivantes. Résultat : import partiel avec rapport d'erreurs, jamais un rollback total à cause d'une seule ligne fautive — conforme au choix demandé en §17 du prompt de phase. Une ligne dupliquée (même SKU) **dans le même fichier** est détectée : les lignes sont traitées séquentiquement, donc la 2ᵉ occurrence trouve la 1ʳᵉ déjà en base au moment de sa validation.

**Limite de lignes** : au-delà de 2000 lignes de données, le fichier entier est rejeté avant tout traitement (`ProductImportRejectedException`, HTTP 422) — pas de traitement partiel d'un fichier disproportionné. Un fichier illisible/corrompu est rejeté de la même façon plutôt que de remonter une 500.

**Format de la réponse** (stable, exploitable par un futur frontend) :

```json
{
  "succes": true,
  "message": "487 produit(s) importé(s), 13 rejeté(s).",
  "donnees": {
    "total_lignes": 500,
    "importes": 487,
    "rejetes": 13,
    "erreurs": [
      { "ligne": 27, "erreurs": { "categorie": ["La catégorie \"Boisson\" est introuvable dans cette boutique."] } },
      { "ligne": 84, "erreurs": { "sku": ["The sku has already been taken."] } }
    ]
  }
}
```

`ligne` compte la ligne réelle du fichier Excel (l'en-tête est la ligne 1, donc la première ligne de données est `2`).

## 9. Préparation de la création vocale

`App\Modules\Catalog\Contracts\VoiceProductParser` — interface à une méthode, **aucune implémentation, aucun fournisseur IA lié**, conformément à la consigne de ne rien imposer prématurément :

```php
interface VoiceProductParser
{
    public function parse(string $transcript): array; // shape ProductRules::rules()
}
```

Architecture future documentée (non codée) :

```
Mobile → audio → Speech-to-Text → VoiceProductParser::parse()
   → tableau structuré → ProductRules::rules() → Validator → ProductService → Product
```

Quand un fournisseur sera choisi : une classe concrète implémente l'interface, se lie dans `CatalogServiceProvider::register()` (`$this->app->bind(VoiceProductParser::class, ConcreteParser::class)`), et un contrôleur/route est ajouté — sans toucher `ProductRules` ni `ProductService`. L'IA ne doit jamais écrire directement en base ; elle ne produit que des données structurées, revalidées comme n'importe quel autre chemin.

## 10. Isolation multi-tenant

Trois couches, comme partout ailleurs :

1. **`BelongsToStore`** sur `Category`/`Product`/`Service` — `boutique_id` forcé depuis `TenantContext`, jamais depuis le payload client.
2. **Scoped route model binding** (`Route::scopeBindings()`) sur tout le groupe `boutiques/{store}/...` — un `{product}` d'un autre store 404 avant tout code applicatif.
3. **Policies** (`CategoryPolicy`, `ProductPolicy`, `ServicePolicy`) — filet de sécurité vérifiant explicitement `$model->boutique_id === $store->id`.

**Validation tenant-aware** : `categorie_id` (saisie manuelle ou résolu par nom à l'import) est toujours vérifié par `TenantScopedRules::existsInCurrentStore('categories')`. `sku`/`code_barres`/`slug` utilisent `TenantScopedRules::uniqueInCurrentStore()`, scopés par store. L'import ne bénéficie d'aucun raccourci : il passe par les mêmes règles.

## 11. Authorization vs FeatureGate

| Mécanisme | Répond à | Implémenté par |
|---|---|---|
| **FeatureGate** | "Cette boutique a-t-elle la capacité `produits` ?" | Middleware `feature:produits` (les routes d'import sont **dans le même groupe** `feature:produits` — pas de feature `catalog.product_import` séparée : importer des produits n'est pas une capacité distincte d'« avoir des produits », voir §12) |
| **Authorization** | "Cet utilisateur a-t-il `produits.importer` ?" | `ProductPolicy::import()`, après FeatureGate |

## 12. Permissions

Ajout de `produits.importer` (à côté de `products.{view,create,update,delete}` déjà existants) — pas de préfixe `catalog.` inventé, cohérent avec la nomenclature déjà en place. Accordée à `proprietaire`/`administrateur`/`gerant` (même niveau que `produits.creer`), pas à `caissier`/`employe` (lecture seule).

Aucune nouvelle `Feature` créée : `categories`, `produits`, `services` existaient déjà depuis la Phase 2 (`FeatureSeeder`) et couvrent aussi l'import — la nomenclature existante ne prévoit pas de feature par sous-action.

## 13. Filtres, pagination, ressources

`GET /produits` accepte `recherche`, `categorie_id`, `actif`, et désormais `mode_prix=detail|wholesale` (utile au futur frontend de caisse, non implémenté ici). Pagination `?par_page=` (défaut 20, plafond 100), inchangée. `ProductResource` expose `prix_achat`, `vente_detail_active`, `prix_detail`, `vente_gros_active`, `prix_gros` (plus `selling_price`).

## 14. Futur contrat avec Sales

Sales n'est pas implémenté dans cette phase. Ce qu'il pourra envoyer, conceptuellement :

```json
{ "produit_id": 15, "quantite": 20, "mode_prix": "gros" }
```

Le backend Sales devra alors : (1) vérifier que `Product` appartient au store courant (scoped binding, comme partout) ; (2) appeler `Product::priceFor(PricingMode::Wholesale)` — lève une `InvalidArgumentException` si `vente_gros_active` est `false`, donc Sales ne peut jamais vendre à un prix désactivé ; (3) calculer le montant côté serveur. Le frontend n'est jamais la source de vérité du prix. `Product::priceFor()` existe déjà (Catalog), prêt à être appelé — aucune logique de vente n'est ajoutée ici.

## 15. Ce qui est volontairement laissé à Inventory / Sales / une phase future

- Aucune colonne de stock sur `Product` — `tracksStock(): bool` existe pour qu'Inventory sache QUOI suivre, sans savoir COMMENT.
- Aucune relation polymorphique construite — attend `SaleItem`/`OrderItem`.
- Aucune contrainte "au moins un mode de vente actif" — non demandée par la phase, un produit avec `vente_detail_active=false` et `vente_gros_active=false` est acceptée par la validation (par ex. un produit en cours de préparation).
- Speech-to-Text réel et fournisseur IA : non choisis, non intégrés (§9).
- `Customers` : toujours non traité (reporté depuis la Phase 3 initiale, hors périmètre de cette révision).

## 16. Photo du produit (optionnelle)

> **Ajout (2026-09-29)** — demandé pour l'application mobile.

- Colonne `produits.image` (nullable) : chemin relatif sur le disque `public` (`produits/{boutique_id}/{uuid}.{ext}`), jamais une URL.
- `ProductResource` expose `image_url` (ou `null`), construite avec `asset()` : elle suit l'hôte de la requête (l'IP du PC vue par le téléphone) au lieu de figer `APP_URL`.
- `POST /api/boutiques/{store}/produits/{product}/image` — `multipart/form-data`, champ `image` (jpg/jpeg/png/webp, contenu réel vérifié, 5 Mo max). Remplace l'image existante et supprime l'ancien fichier.
- `DELETE /api/boutiques/{store}/produits/{product}/image` — retire l'image et supprime le fichier.
- Hors du CRUD JSON : `image` n'est ni dans `ProductRules` ni dans `$fillable` ; création/modification restent en JSON, la photo est un envoi séparé. Même autorisation que la modification (`ProductPolicy::update`, `produits.modifier`), même isolation par boutique (scoped binding → 404).
- Prérequis serveur : `php artisan storage:link` (lien `public/storage`).
- Tests : `tests/Feature/Modules/Catalog/ProductImageTest.php`.

## Import Excel : stock initial (2026-10-11)

Colonnes optionnelles `stock_initial` et `stock_minimum`. Si la boutique suit le stock (feature `stock`), une valeur `stock_initial` initialise le stock du produit dans la même transaction que sa création (contrat `Catalog\Contracts\InitialStockRecorder`, implémenté par Inventory) ; sinon les colonnes sont ignorées. Le rapport ajoute `stocks_initialises`.
