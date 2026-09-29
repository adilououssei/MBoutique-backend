# Stratégie multi-tenant

> **Mise à jour (audit architectural du 2026-09-06)** : voir [audit-2026-09.md](audit-2026-09.md). Ajouts issus de l'audit : Couche 5 "Validation des références" et Couche 6 "Route model binding scopé" (§3), scénarios de fuite explicites produit/vente/client (§4), exception contrôlée pour les transferts inter-boutiques (§7), risque de fuite via un worker long-lived type Octane (§8).

C'est la partie la plus critique du projet : une fuite de données entre boutiques (l'utilisateur de la Boutique 3 voit une donnée de la Boutique 1) est le pire scénario possible. Cette stratégie est conçue en **défense en profondeur** : plusieurs couches indépendantes doivent toutes échouer pour qu'une fuite se produise.

## 1. Options évaluées pour l'isolation

| Option | Description | Verdict |
|---|---|---|
| Base de données séparée par tenant | Une DB MySQL par `Store` ou par `Business` | Rejeté : complexité opérationnelle énorme (migrations ×N, connexions dynamiques, rapports cross-store impossibles) pour un besoin (PME multi-boutiques) qui ne le justifie pas |
| Schéma séparé par tenant | Un schéma MySQL par tenant, même serveur | Rejeté : mêmes problèmes de migration à grande échelle, MySQL gère moins bien le multi-schéma que Postgres |
| **Shared database, shared schema, colonne `boutique_id`** | Toutes les boutiques dans les mêmes tables, isolées par une colonne + scoping applicatif systématique | **Retenu** : adapté au volume attendu, migrations uniques, rapports cross-store triviaux pour un même Business, coût d'isolation géré par la discipline applicative décrite ci-dessous |

Le risque de l'option retenue est entièrement dans "la discipline applicative" — d'où les couches de défense qui suivent.

## 2. Modèle d'identification du tenant

```
User ──< StoreUser (status=active) >── Store ──< Business
```

Un `User` n'a pas de "boutique courante" stockée en base : le tenant courant est déterminé **à chaque requête**, jamais mis en session de façon durable côté serveur (pour permettre à un même utilisateur de basculer entre ses boutiques sans état serveur à invalider).

### Comment le backend identifie la boutique courante

Convention d'URL : `GET /api/boutiques/{store}/produits`. Le paramètre `{store}` est résolu par **route model binding** Laravel, puis vérifié par un middleware dédié avant tout traitement métier :

```php
// app/Modules/Tenancy/Http/Middleware/ResolveStoreContext.php (à implémenter à la phase Tenancy)
public function handle(Request $request, Closure $next)
{
    $store = $request->route('store'); // résolu par binding {store} -> Store::class

    $membership = StoreUser::where('store_id', $store->id)
        ->where('user_id', $request->user()->id)
        ->where('status', 'active')
        ->first();

    abort_if(! $membership, 403, 'Vous n\'êtes pas membre de cette boutique.');

    app(TenantContextContract::class)->setStoreId($store->id);

    return $next($request);
}
```

Points clés :
- **Décision prise à l'implémentation (Phase 1)** : toujours **404**, jamais 403, quelle que soit la raison (store inexistant, utilisateur jamais invité, membre révoqué) — aucune de ces situations ne doit être distinguable de l'extérieur. Implémenté dans `App\Modules\Tenancy\Http\Middleware\ResolveStoreContext`.
- Le contrôle se fait **avant** tout accès aux données métier de la route, dans un middleware, jamais dans chaque controller individuellement (sinon un seul contrôleur oublié = fuite).
- `TenantContext` (déjà implémenté, `app/Shared/Tenancy/TenantContext.php`) porte le `boutique_id` validé pour le reste de la requête — c'est la seule source de vérité consommée en aval (jamais un `$request->route('store')` relu plus loin dans la pile).

## 3. Défense en profondeur — les 6 couches

### Couche 1 — Middleware de résolution (§2)
Bloque l'accès à la route elle-même si l'utilisateur n'est pas membre actif du store demandé dans l'URL.

### Couche 2 — Global Scope Eloquent (`BelongsToStore`, déjà implémenté)
`app/Shared/Tenancy/Concerns/BelongsToStore.php` ajoute un scope global `where('boutique_id', TenantContext::getStoreId())` sur **tout** modèle qui l'utilise (Product, Sale, Customer, ...). Conséquence : même si un développeur écrit `Product::find($id)` sans filtrer explicitement par store, la requête SQL générée inclut déjà le filtre. C'est la couche qui protège contre l'erreur humaine la plus fréquente (oubli d'un `->where('boutique_id', ...)`).

**Piège à documenter et tester** : le scope global ne protège pas contre `Product::withoutGlobalScope('store')->find($id)` utilisé par erreur, ni contre une requête SQL brute (`DB::table('produits')`). Règle d'équipe : `DB::table()` est interdit sur les tables tenant-scopées en dehors de migrations/seeders ; toute requête métier passe par Eloquent.

### Couche 3 — Policies (autorisation sur l'instance)
Même si le scope global limite déjà les résultats des listes, un accès direct par id (`GET /api/boutiques/{store}/ventes/{sale}`) doit vérifier explicitement, dans une `Policy`, que `$sale->boutique_id === $store->id` (ceinture + bretelles avec la couche 2, utile en particulier si un jour une requête a besoin de `withoutGlobalScope` pour un cas d'administration plateforme).

```php
class SalePolicy
{
    public function view(User $user, Sale $sale, Store $store): bool
    {
        return $sale->store_id === $store->id
            && $user->can('ventes.voir'); // permission scopée au store via spatie teams
    }
}
```

### Couche 4 — Permissions scopées par store (spatie/laravel-permission, teams)
Un rôle/permission accordé à un utilisateur sur le Store A n'existe pas sur le Store B (voir [permissions.md](permissions.md)). Même si un attaquant forge une requête pour le Store B, la vérification `$user->can('produits.voir')` échoue si `TenantContext` pointe sur le Store B et que l'utilisateur n'y a aucun rôle — indépendamment du filtrage de données.

### Couche 5 — Validation des références (ajoutée suite à l'audit — comble un angle mort réel)
Les couches 1 à 4 protègent la **lecture** (qui peut voir/agir sur quoi). Elles ne protègent pas une **écriture qui référence l'ID d'une autre ressource** dans son payload. Exemple concret : `POST /api/boutiques/{storeA}/ventes` avec un corps `{ "client_id": 42, "lignes": [...] }` où `client_id = 42` appartient en réalité au Store B. Une règle de validation naïve `Rule::exists('clients', 'id')` accepte cette requête (le client 42 existe bel et bien, juste pas dans ce store) : la vente créée dans le Store A référence alors un client du Store B, et tout endpoint qui affiche `vente.client.nom` fait fuiter cette donnée vers le Store A. C'est une **injection de relation inter-tenant** — le même risque qu'un IDOR classique, mais via une clé étrangère du payload plutôt qu'un paramètre d'URL.

**Règle obligatoire, sans exception** : toute règle de validation `exists` sur un champ qui référence une ressource tenant-scopée (`client_id`, `produit_id`, `service_id`, `employee_id`, `supplier_id`, `table_id`, ...) doit être scopée au store courant :

```php
// FormRequest de n'importe quel module — jamais 'exists:customers,id' seul
'customer_id' => [
    'nullable',
    Rule::exists('customers', 'id')->where('store_id', $this->route('store')->id),
],
```

À formaliser dès la Phase 1 comme une règle de revue de code non négociable (voir [testing.md](testing.md) — nouvelle catégorie de test "IDOR par référence").

### Couche 6 — Route model binding scopé (ajoutée suite à l'audit — durcit la Couche 3)
Par défaut, `Route::get('boutiques/{store}/produits/{product}', ...)` résout `{product}` par sa seule clé primaire, indépendamment de `{store}` — la Policy (Couche 3) est donc la **seule** chose qui empêche `/boutiques/{storeA}/produits/{productIdDeStoreB}` d'atteindre le controller avec un `$product` valide (juste du mauvais store). C'est un point unique de défaillance : un développeur qui oublie la vérification dans une Policy, ou une Policy mal écrite, laisse passer l'accès. Laravel fournit une protection native gratuite pour ce cas précis : le **scoped binding**, qui fait échouer la résolution de route elle-même (404, avant même d'entrer dans le controller) si l'enfant n'appartient pas au parent :

```php
Route::resource('stores.products', ProductController::class)->scoped([
    'product' => 'id', // vérifie automatiquement que $product->store()->is($store)... 
]);
// Nécessite que Product::store() existe et que le nom du paramètre de route
// diffère du nom du paramètre parent — déjà le cas ici ({store} vs {product}).
```

Cette couche ne remplace pas la Policy (qui vérifie aussi la permission, pas seulement l'appartenance), mais transforme une éventuelle Policy manquante ou buguée d'une fuite de données en une simple 404 — un filet de sécurité au niveau du framework plutôt qu'au niveau de chaque développeur. À appliquer systématiquement sur toutes les routes imbriquées `stores.{ressource}` dès leur création.

## 4. Où une fuite pourrait se produire, et comment elle est empêchée

| # | Scénario (repris des exemples de l'audit) | Cause | Parade |
|---|---|---|---|
| 1 | `GET /api/boutiques/store-B/produits` demandé par un utilisateur membre uniquement du Store A | Tentative d'accès direct par URL | Couche 1 : `ResolveStoreContext` vérifie l'appartenance à `store-B` avant tout traitement → 403/404 immédiat |
| 2 | Un controller fait `Product::all()` sans passer par le store courant | Oubli développeur | Couche 2 (scope global) rend la fuite impossible même en cas d'oubli |
| 3 | `GET /api/boutiques/{storeA}/produits/{productIdDeStoreB}` — un `produit_id` de Store B manipulé alors que l'URL porte `storeA` | Route model binding résout l'objet par ID seul, sans vérifier son store | Couche 6 (scoped binding) → 404 avant le controller ; Couche 3 (Policy) → 403 en filet de sécurité si la Couche 6 n'est pas encore appliquée sur cette route |
| 4 | Idem avec un `vente_id` ou un `client_id` de Store B dans l'URL (`GET /boutiques/{storeA}/ventes/{saleIdDeStoreB}`, `.../clients/{customerIdDeStoreB}`) | Identique au cas 3, tout ID de ressource tenant-scopée est concerné, pas seulement `produit_id` | Identique : Couche 6 + Couche 3, à appliquer uniformément à **toute** route `show/update/delete` de **tout** module, pas seulement Sales/Catalog |
| 5 | `POST /boutiques/{storeA}/ventes` avec `client_id`/`produit_id` appartenant au Store B dans le corps de la requête (pas dans l'URL) | Validation `exists` non scopée | Couche 5 (validation scopée), voir §3 — **angle mort non couvert par les couches 1 à 4**, c'est le cas le plus facile à oublier |
| 6 | Un token Sanctum valide mais l'utilisateur a été retiré du store depuis (`StoreUser.status = revoked`) | Le token reste valide indépendamment de l'appartenance | Couche 1 revérifie l'appartenance **à chaque requête**, jamais mise en cache dans le token/session |
| 7 | Une queue/job asynchrone traite une donnée sans `TenantContext` (contexte de requête HTTP absent en job) | `TenantContext` est un singleton per-request, vide dans un job | Tout job métier doit recevoir explicitement le `boutique_id` en paramètre et l'injecter manuellement dans `TenantContext` en début de `handle()`, jamais supposer qu'il est déjà renseigné — à documenter comme règle obligatoire dans le module `Shared` au moment où les premiers jobs apparaissent |
| 8 | Un rapport agrège par erreur toutes les boutiques d'un `Business` au lieu d'une seule | Confusion volontaire Business vs Store dans une requête de reporting | `Reports` doit explicitement choisir : requête scopée `boutique_id` (cas normal) vs requête explicitement documentée "vue consolidée Business" nécessitant une permission dédiée (`rapports.voir_consolide`) distincte de `rapports.voir` |
| 9 | Un administrateur plateforme (support) doit consulter une boutique sans en être membre | Cas légitime mais dangereux si mal isolé | Rôle `platform_admin` séparé du système de permissions par store, accès en lecture uniquement, journalisé (audit log), jamais le même mécanisme que l'appartenance normale à un store — voir [permissions.md](permissions.md) §6 |
| 10 | Upload de fichier (ex: photo produit) stocké dans un chemin prévisible | `storage/produits/123.jpg` devinable | Chemins de stockage doivent inclure `boutique_id` dans le path et être servis via une route contrôlée par policy, jamais un accès disque public direct pour des données sensibles |
| 11 | `POST /boutiques/{storeA}/produits` avec `"boutique_id": <idDeStoreB>` dans le corps (mass assignment) | Un `boutique_id` client pourrait, en théorie, écraser celui déduit du contexte | **Corrigé pendant cet audit** : `BelongsToStore::creating` impose désormais toujours le `boutique_id` du `TenantContext`, quelle que soit la valeur envoyée par le client, et `BelongsToStore::updating` interdit toute modification ultérieure de `boutique_id` (`app/Shared/Tenancy/Concerns/BelongsToStore.php`) |
| 12 | `Product::withoutGlobalScope('store')->find($id)` utilisé par erreur dans du code métier normal | Contournement volontaire ou accidentel de la Couche 2 | Convention d'équipe stricte : `withoutGlobalScope('store')` n'est autorisé que dans du code explicitement dédié à l'administration plateforme, jamais dans un controller/service d'un module métier normal ; chaque usage doit porter un commentaire justificatif et être grep-able en revue (`grep -rn "withoutGlobalScope('store')" app/Modules`) |

## 5. Cas d'un utilisateur multi-boutiques

```
User A
├── Store 1 (rôle: propriétaire)
└── Store 2 (rôle: caissier)
```

Chaque requête API porte explicitement `{store}` dans l'URL — il n'y a jamais d'ambiguïté côté serveur sur "quelle boutique". Le frontend (React/mobile) est responsable de l'UX de sélection de boutique (ex: un sélecteur après connexion), mais le backend ne fait **jamais confiance** à un état "boutique active" côté client au-delà de ce qui est vérifié à chaque requête par la Couche 1. `GET /api/me/boutiques` (module `Tenancy`) retourne la liste des boutiques dont l'utilisateur est membre actif, avec son rôle sur chacune, pour alimenter ce sélecteur.

## 7. Exception contrôlée : transferts de stock inter-boutiques (ajouté suite à l'audit)

Le module `Inventory` a un besoin légitime de faire traverser la frontière tenant : transférer du stock d'une boutique à une autre (deux boutiques du même commerçant). Ce n'est **pas** une fuite si, et seulement si, toutes ces conditions sont vérifiées par le service applicatif (jamais par un simple appel Eloquent direct) :

1. Les deux stores partagent le même `entreprise_id` (vérifié en base, jamais fait confiance à l'input).
2. L'utilisateur possède la permission `stock.transfer` sur le store **source**.
3. L'utilisateur est membre actif du store **destination** également (sinon : un transfert ne doit pas pouvoir déposer du stock dans une boutique où l'auteur n'a aucun droit).
4. L'opération écrit atomiquement un `StockMovement(type: transfer_out)` sur la source et un `StockMovement(type: transfer_in)` sur la destination, dans une seule transaction DB.
5. L'opération est journalisée (qui, quand, combien, entre quelles boutiques) — c'est la seule catégorie d'écriture inter-store de tout le système, elle mérite un log dédié plutôt que de se fondre dans le log applicatif général.

Toute autre tentative de faire écrire un module dans plus d'un `boutique_id` à la fois (hors ce cas précis et explicitement whitelisté) doit être considérée comme un bug de conception à corriger, pas comme un nouveau cas d'usage à accommoder.

## 8. Risque à surveiller : workers long-lived (Octane, queues)

`TenantContext` est actuellement un singleton **par requête** — correct sous PHP-FPM classique où chaque requête HTTP redémarre le conteneur d'injection de dépendances. Si le projet adopte un jour Laravel Octane (ou tout worker persistant qui garde le process PHP vivant entre requêtes), ce singleton **doit être explicitement réinitialisé** au début de chaque requête, sinon le `boutique_id` d'une requête pourrait fuiter dans la requête suivante traitée par le même worker — un scénario de fuite inter-tenant particulièrement sournois car il ne serait pas provoqué par l'attaquant lui-même mais par un utilisateur légitime précédent. `spatie/laravel-permission` a d'ailleurs une option dédiée à ce risque (`config/permission.php` → `register_octane_reset_listener`, actuellement `false` car Octane n'est pas utilisé). **Décision** : ne pas activer Octane sans, dans le même changement, (a) passer `register_octane_reset_listener` à `true` et (b) enregistrer un listener équivalent qui appelle `TenantContext::clear()` sur `Laravel\Octane\Events\RequestReceived`. Documenté ici pour que ce ne soit pas redécouvert en production le jour où Octane est activé pour des raisons de performance sans repasser par cette checklist.

## 9. Ce qui est déjà implémenté vs ce qui reste à faire

| Élément | État |
|---|---|
| `TenantContextContract` / `TenantContext` | ✅ Implémenté (`app/Shared/Tenancy/`) |
| `BelongsToStore` (scope global + `boutique_id` toujours forcé depuis le contexte, immuable après création) | ✅ Implémenté, corrigé lors de l'audit du 2026-09-06, testé (`tests/Unit/Shared/BelongsToStoreTest.php`) |
| `StoreTeamResolver` (branche `TenantContext` sur spatie teams) | ✅ Implémenté, **corrigé en Phase 1** (incompatibilité avec l'instanciation sans conteneur de `PermissionRegistrar` — voir `docs/stores.md`) |
| `ResolveStoreContext` middleware | ✅ Implémenté en Phase 1 (`app/Modules/Tenancy/Http/Middleware/`), toujours 404 pour un non-membre, `terminate()` nettoie `TenantContext` |
| Policies (`BusinessPolicy`, `StorePolicy`) | ✅ Implémentées en Phase 1 pour Business/Store ; à répliquer module par module ensuite |
| Scoped route bindings (Couche 6) | ⏳ Aucune route de cette phase n'a de vrai besoin (le seul cas nested, `entreprises/{business}/boutiques`, n'a pas d'endpoint `show` par id imbriqué) — à appliquer dès la première route imbriquée qui en a réellement besoin (Sales/Orders sous Store, Phase 4/5) |
| Validation scopée des références (Couche 5) | ✅ Mécanisme implémenté et testé (`App\Shared\Validation\TenantScopedRules`, `tests/Unit/Shared/TenantScopedRulesTest.php`) — aucun `FormRequest` de cette phase n'a encore de champ concerné (les seules références sont des `User` globaux), sera consommé dès Catalog/Sales |
| Tests d'isolation systématiques (accès direct **et** injection de référence) | ✅ `tests/Feature/Modules/Tenancy/MultiTenantIsolationTest.php` — couvre l'accès direct, le `boutique_id` mass-assigné, et la référence à une ressource d'un autre store |
