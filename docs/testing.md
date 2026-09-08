# Stratégie de tests

> **Mise à jour (implémentation Phase 1)** : la stratégie ci-dessous est maintenant appliquée concrètement — 43 tests dans `tests/Feature/Modules/{Auth,Tenancy}/` et `tests/Unit/Shared/`, tous verts (`php artisan test`). En particulier `tests/Feature/Modules/Tenancy/MultiTenantIsolationTest.php` couvre exactement les 3 scénarios critiques de ce document (accès direct, `store_id` mass-assigné, référence à une ressource d'un autre store), et `tests/Unit/Shared/BelongsToStoreTest.php`/`TenantScopedRulesTest.php` prouvent directement les Couches 2 et 5 de `docs/multi-tenancy.md`.
>
> **Mise à jour (implémentation Phase 2)** : 76 tests au total. Ajoutés : `tests/Feature/Modules/Features/*` (domaines, features, isolation multi-tenant des overrides, endpoint `/stores/{store}/features`, indépendance Feature/Permission via une route de test dédiée), `tests/Unit/Modules/Features/FeatureGateTest.php` (résolution complète : domaine, override, dépendances, plan), `tests/Unit/Modules/Subscriptions/SubscriptionLimitsTest.php`.

## 1. Niveaux de test

| Niveau | Portée | Exemple |
|---|---|---|
| Unit | Une classe isolée, sans framework HTTP ni DB si possible | `FeatureGate::resolve()` avec des objets en mémoire |
| Feature/API | Une route complète, DB de test (SQLite en mémoire ou MySQL de test), assertions sur la réponse JSON | `POST /api/stores/{store}/products` retourne 201 et la ressource attendue |
| Autorisation | Vérifie qu'un utilisateur sans la permission/le rôle requis reçoit 403 | Voir §3 |
| **Isolation multi-tenant** | Vérifie qu'un utilisateur d'un store ne peut jamais atteindre les données d'un autre store | Voir §2, catégorie de test **obligatoire** pour tout nouvel endpoint |

## 2. Test d'isolation multi-tenant — le test qui doit exister pour CHAQUE ressource

Modèle systématique à dupliquer pour chaque module métier (Products, Sales, Customers, ...) :

```php
public function test_user_cannot_access_another_stores_resource(): void
{
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();

    $userA = User::factory()->create();
    StoreUser::factory()->for($storeA)->for($userA)->create(['status' => 'active']);

    $resourceInStoreB = Product::factory()->for($storeB)->create();

    $response = $this->actingAs($userA)
        ->getJson("/api/stores/{$storeB->id}/products/{$resourceInStoreB->id}");

    $response->assertStatus(403); // ou 404 selon la décision prise en multi-tenancy.md §2
}
```

Variantes obligatoires du même principe :
- Lister une collection (`GET /stores/{storeB}/products`) avec le token de userA qui n'est pas membre de storeB → refusé avant même d'atteindre la logique de liste.
- userA est membre de storeB mais avec `status = revoked` → refusé.
- userA est membre actif de storeB mais sans la permission `products.view` → 403 (test de permission, distinct du test d'isolation, mais souvent écrit côte à côte).
- Tentative de update/delete sur une ressource d'un store dont on n'est pas membre → refusé au même titre que la lecture.

Cette suite de tests doit être écrite **dès le premier module métier implémenté** (probablement `Tenancy` + un module simple comme `Customers`), pour valider le pipeline middleware/scope/policy décrit dans [multi-tenancy.md](multi-tenancy.md) sur un cas réel avant de le répliquer partout.

## 2 bis. Test d'injection de relation inter-tenant (ajouté suite à l'audit)

Catégorie distincte du §2 : ne teste pas un accès direct par ID, mais une **référence à un ID d'un autre store dans le corps d'une requête** — l'angle mort identifié dans [multi-tenancy.md](multi-tenancy.md) Couche 5.

```php
public function test_cannot_create_sale_referencing_a_customer_from_another_store(): void
{
    $storeA = Store::factory()->create();
    $customerInStoreB = Customer::factory()->for(Store::factory())->create();

    $response = $this->actingAs($userMemberOfStoreA)
        ->postJson("/api/stores/{$storeA->id}/sales", [
            'customer_id' => $customerInStoreB->id,
            'items' => [...],
        ]);

    $response->assertStatus(422); // rejeté par la validation scopée, pas 500 ni 201
}
```

À dupliquer pour chaque champ `*_id` référençant une ressource tenant-scopée, dans chaque module (Sales→customer_id/product_id, Appointments→service_id/employee_id/customer_id, Orders→table_id, etc.).

## 3. Tests d'autorisation (permissions)

```php
public function test_cashier_cannot_delete_products(): void
{
    // utilisateur avec le rôle "Caissier" (sans products.delete) sur ce store
    $response = $this->actingAs($cashier)->deleteJson(".../products/{$product->id}");
    $response->assertStatus(403);
}
```

À croiser avec un test positif symétrique (le rôle qui a la permission peut effectivement agir), pour éviter un faux sentiment de sécurité où seul le cas "refusé" est testé.

## 4. Organisation des tests

- `tests/Feature/Modules/<Module>/...` reflète `app/Modules/<Module>/...` (miroir de structure, pas de dossier `tests/Feature` plat).
- Chaque module possède aussi ses tests dans son propre dossier `app/Modules/<Module>/Tests/` **ou** dans `tests/Feature/Modules/<Module>/` selon la convention Laravel retenue au démarrage de l'implémentation — recommandation : garder les tests dans `tests/` (convention Laravel standard, meilleure intégration avec PHPUnit/Pest par défaut) plutôt que dans le module lui-même, pour ne pas avoir deux endroits où chercher les tests d'un projet.
- Factories co-localisées avec chaque module (`app/Modules/<Module>/Database/Factories/`) pour que la suppression d'un module reste une suppression de dossier complète.

## 5. Base de données de test

Recommandation : MySQL de test (pas SQLite) dès que possible, pour que les contraintes/comportements testés (types JSON, collations, comportement des transactions) correspondent à la production — SQLite en mémoire acceptable en démarrage pour la vitesse, à réévaluer si des divergences de comportement apparaissent (fréquent avec les colonnes JSON et certaines contraintes uniques composites).

## 6 bis. Tests spécifiques au module Sales (ajoutés suite à l'audit)

En plus des tests d'isolation et d'autorisation standards, le module `Sales` a deux catégories de test propres à son caractère transactionnel (voir [database.md](database.md) §11/§7) :

- **Atomicité** : une vente qui échoue à mi-parcours (ex: stock insuffisant détecté sur le deuxième article du panier) ne doit laisser **aucune trace** en base — ni `Sale`, ni `SaleItem` déjà insérés, ni `StockMovement` du premier article. Test : provoquer l'échec, puis vérifier `Sale::count()` inchangé et `StockMovement::count()` inchangé.
- **Idempotence** : deux requêtes de création identiques (même `idempotency_key`) ne doivent produire qu'une seule `Sale` et qu'un seul jeu de `StockMovement` — test : appeler l'endpoint deux fois avec le même payload/`idempotency_key`, vérifier `Sale::count() === 1`.

## 6. CI

Chaque pull request exécute : `phpunit`/`pest` complet, `laravel/pint` (style), et une vérification statique (`phpstan`/`larastan`, à ajouter dès le premier module pour attraper les erreurs de type tôt — pas encore installé à ce stade de conception). Le pipeline échoue si la couverture des tests d'isolation multi-tenant (§2) est absente sur une nouvelle route acceptant un `{store}` — à terme, formaliser cette règle par une convention de nommage de test détectable automatiquement (ex: exiger un test nommé `*_cannot_access_another_stores_*` par contrôleur), plutôt qu'un contrôle purement humain en revue de code.
