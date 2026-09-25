# Inventory (Phase 4.1)

Module `app/Modules/Inventory/`. Suivi des quantités disponibles des `Product` du Catalog, et historique complet de ce qui les a fait varier.

> **Écart d'architecture assumé et documenté** : `docs/database.md` §11 avait tranché, avant cette phase, pour une colonne dénormalisée `products.current_stock` plutôt qu'un modèle `Stock` séparé. Le prompt de la Phase 4.1 demande explicitement un modèle `Stock` distinct. Décision prise avec l'utilisateur : suivre la nouvelle demande. `docs/database.md` §6/§11 ont été mis à jour en conséquence (voir la note de révision qui y est ajoutée) — ceci n'est pas une contradiction silencieuse, le changement est documenté aux deux endroits.

## 1. Rôle du module

Répondre à tout moment à : *pour ce produit, dans ce store, combien en reste-t-il ?* et *qu'est-ce qui a fait varier cette quantité, et pourquoi ?* — sans jamais dupliquer le modèle `Product` (le module référence `App\Modules\Catalog\Models\Product`, n'en recrée pas un second).

## 2. Stock vs StockMovement

```
Product (Catalog)
   │
   ▼
Stock (état courant — un par produit par store)
   │
   └── StockMovement[] (historique append-only)
```

| Modèle | Rôle | Table |
|---|---|---|
| `Stock` | Cache de l'état courant : `quantity`, `minimum_quantity`. **Pas** la source de vérité. | `stocks`, unique `(store_id, product_id)` |
| `StockMovement` | Source de vérité : chaque ligne est un fait historique immuable. | `stock_movements` |

`Stock.quantity` est recalculé à chaque écriture de `StockMovement`, dans la **même transaction** — jamais l'inverse (jamais un controller qui fait `$stock->quantity += 10` puis en déduit un mouvement).

## 3. Ledger append-only — aucune exception

Une fois créé, un `StockMovement` n'est **jamais** modifié ni supprimé. Aucune route `PUT`/`DELETE` sur un mouvement n'existe dans ce module — vérifié par test (`test_a_stock_movement_cannot_be_updated_or_deleted_through_the_api`, qui s'appuie sur le fait que ces routes n'existent tout simplement pas : 404, pas 403). Une erreur constatée après coup s'exprime comme un **nouveau** mouvement compensatoire (ex: `+100` erroné suivi d'un `-20` correctif), jamais comme une édition du premier.

## 4. Types de mouvements

Exactement les huit demandés par la Phase 4.1 — aucun ajouté (pas de `transfer_in`/`transfer_out` malgré leur présence dans la conception d'origine de `database.md`, voir §14 "reporté") :

| Type | Sens | Qui peut l'enregistrer manuellement |
|---|---|---|
| `initial` | entrée | une seule fois par produit — voir §9 |
| `purchase` | entrée | oui |
| `return_in` | entrée | oui |
| `adjustment_in` | entrée | oui |
| `adjustment_out` | sortie | oui |
| `loss` | sortie | oui |
| `stocktake` | delta (les deux sens) | oui, voir §10 |
| `sale` | sortie | **non** — réservé au futur module Sales, voir §13 |

`StockMovementType::manuallyRecordable()` exclut délibérément `sale` : `CreateStockMovementRequest` le rejette (`Rule::in`), même si l'enum le connaît déjà (nécessaire pour que le ledger sache le représenter le jour où Sales existera).

## 5. Quantités : DECIMAL(12,3), jamais float

`stocks.quantity`/`minimum_quantity` et `stock_movements.quantity`/`quantity_before`/`quantity_after` sont tous `DECIMAL(12,3)` — 3 décimales pour couvrir kg/litre/mètre sans imposer un entier strict, tout en restant un type exact (jamais `float`/`double`). Les prix restent `DECIMAL(12,2)`, inchangé (Catalog).

Toute arithmétique sur ces quantités (`InventoryService::applyDelta()`) passe par **bcmath** (`bcadd`/`bcsub`/`bcmul`/`bccomp`), pas par des opérateurs PHP natifs sur les valeurs castées en `decimal:3` (qui sont des chaînes) — un ledger qui accumule des milliers de mouvements ne doit jamais dériver à cause d'une imprécision flottante.

## 6. Stock négatif interdit

`InventoryService::removeStock()` vérifie `bccomp($stock->quantity, $quantity, 3) < 0` **avant** d'écrire quoi que ce soit ; si la sortie demandée dépasse le disponible, `InsufficientStockException` est levée, capturée par le contrôleur et traduite en `422 INSUFFICIENT_STOCK` — jamais une 500, jamais un stock qui devient négatif.

## 7. Transactions et concurrence

Chaque méthode d'écriture de `InventoryService` (`initializeStock`, `addStock`, `removeStock`, `stocktake`) s'exécute dans `DB::transaction()` et verrouille la ligne `Stock` concernée via `lockForUpdate()` avant de lire sa quantité — la seconde d'deux opérations concurrentes sur le même produit attend que la première commite avant de lire une quantité à jour, empêchant le classique "lire-puis-écrire" qui autoriserait une survente.

**Limite de test assumée** (voir la consigne de la Phase 4.1 §37) : la suite de tests tourne sur SQLite in-memory, une connexion unique — un vrai test multi-connexions concurrent n'y est pas reproductible, et SQLite lui-même ignore `lockForUpdate()` (pas de verrouillage ligne par ligne). `test_two_sequential_exits_that_together_exceed_stock_cannot_both_succeed` documente explicitement cette limite dans son docblock et vérifie la seule chose testable ici : le résultat métier que le verrouillage doit garantir une fois en production (MySQL) reste correct de façon séquentielle. La revue de code de `InventoryService` est le complément nécessaire pour confirmer que `lockForUpdate()` est réellement présent sur le chemin d'écriture.

## 8. Le petit tour de course entre l'initialisation et le reste

`initializeStock()` a une garde différente des autres méthodes : comme `Stock` n'existe pas encore, il n'y a rien à verrouiller. La garantie vient de la contrainte unique `(store_id, product_id)` en base — un doublon concurrent lève une `QueryException` (SQLSTATE `23000`), interceptée et retraduite en `StockAlreadyInitializedException` plutôt que de remonter en 500. Une vérification `exists()` préalable couvre déjà l'immense majorité des cas (double-clic, retry) avec un message clair sans même toucher la base une seconde fois inutilement.

## 9. Initialisation du stock

Décision (Phase 4.1 §16, deuxième option retenue) : **aucun** `Stock` n'est créé automatiquement à la création d'un `Product` — Catalog reste inchangé, ignorant totalement Inventory (voir §12). Un `Stock` naît au premier mouvement `type=initial` pour ce produit. Tant qu'aucun mouvement n'existe :

- `GET /inventory/{product}` répond `200` avec un état virtuel `quantity=0`, `minimum_quantity=null`, **pas** un 404 — un produit sans stock renseigné est un état normal et fréquent, pas une erreur.
- Tout type autre que `initial` est rejeté (`422 STOCK_NOT_INITIALIZED`).
- `initial` ne peut être utilisé qu'une seule fois (`422 STOCK_ALREADY_INITIALIZED` ensuite).
- `PUT /inventory/{product}` (seuil minimum) exige que le stock existe déjà (`404 STOCK_NOT_INITIALIZED`) — pas de création implicite via cette route, seul un mouvement crée un `Stock`.

## 10. Stocktake

`counted_quantity` (le compte physique absolu), pas `quantity` (une magnitude non signée) — champ distinct pour éviter l'ambiguïté "quantity=5 ou delta=-5" explicitement soulevée par la Phase 4.1 §33. Le delta (`counted_quantity - quantity_before`) est calculé par `InventoryService::stocktake()`, jamais par le client. Exemple vérifié par test : système=100, compté=96 → mouvement `quantity=-4`.

## 11. Contrat de validation (`CreateStockMovementRequest`)

Un seul endpoint, un seul Request, pour les sept types manuellement enregistrables : `type` (obligatoire), puis soit `quantity` (`required_unless:type,stocktake`, `gt:0` — jamais zéro ni négatif) soit `counted_quantity` (`required_if:type,stocktake`, `min:0` — zéro est un compte physique valide). `minimum_quantity` n'est acceptée qu'avec `type=initial` (`prohibited_unless`). `reason` est toujours facultatif, y compris pour un ajustement manuel — non imposé pour tous les mouvements (Phase 4.1 §21).

## 12. Services exclus du stock

Les routes Inventory sont keyed par `{product}`, scopées via `Store::products()` (la même relation que Catalog) — un `Service` n'apparaît jamais dans cette relation, donc il ne peut structurellement jamais atteindre `InventoryService`. Aucune vérification `instanceof`/`if` supplémentaire n'est nécessaire ; c'est le typage (`Product` partout, jamais `Sellable`) qui l'empêche.

## 13. Isolation multi-tenant

Mêmes couches que Catalog/Customers : `BelongsToStore` sur `Stock`/`StockMovement`, `Route::scopeBindings()` sur `{product}` (via `Store::products()`, déjà existante — aucune relation `Store::stocks()` n'était nécessaire puisque `Stock` n'est jamais un paramètre de route). `Stock`/`StockMovement` ne sont eux-mêmes jamais résolus depuis un id fourni par le client : `InventoryService` les retrouve toujours via `product_id` déjà validé par le binding scopé de `{product}`.

## 14. Futur contrat avec Sales

Non implémenté ici. Quand `Sales` existera :

```
Sale → SaleItem → Product → InventoryService::removeStock(product, StockMovementType::Sale, quantity, userId)
                                       │
                                       ▼
                              StockMovement(type: sale)
```

Sales ne doit **jamais** faire `$stock->quantity -= $x` directement — seul `InventoryService` écrit le ledger, garantissant une source de vérité unique (Phase 4.1 §40). `reference_type`/`reference_id` (morph, déjà en base sur `stock_movements`, pas encore alimentés) sont prêts à recevoir `Sale`/`SaleItem` le moment venu, sans migration supplémentaire.

`transfer_in`/`transfer_out` (transferts inter-boutiques, documentés dans la conception d'origine de `database.md` §6 et `multi-tenancy.md` §7) sont délibérément **hors périmètre** de cette phase — non demandés par le prompt Phase 4.1, non implémentés.

## 15. Permissions

`inventory.view`, `inventory.adjust`, `inventory.stocktake` — trois permissions distinctes, pas deux, conformément à la distinction explicite demandée par la Phase 4.1 §27 entre "ajuster" et "faire un inventaire" (`docs/permissions.md` §3 n'en illustrait que deux, mais cette liste n'a jamais été présentée comme exhaustive — cf. précédent identique pour `products.import` en Phase 3).

| Rôle | Accès stock |
|---|---|
| owner / admin / manager | `view` + `adjust` + `stocktake` (`docs/permissions.md` §3 nomme "stock" explicitement dans le périmètre du manager) |
| cashier / employee | `view` uniquement — même palier que Catalog/Customers |

`inventory.adjust` couvre tous les types manuels sauf `stocktake` (y compris `initial`) ; `inventory.stocktake` est vérifiée séparément par `StockMovementPolicy::create()` selon le `type` demandé — testé explicitement (`test_inventory_adjust_and_inventory_stocktake_are_independent_permissions`).

## 16. FeatureGate

Aucune nouvelle `Feature` créée : `inventory` existait déjà depuis la Phase 2 (`FeatureSeeder`), incluse dans les domaines qui vendent des produits physiques (alimentation générale, boucherie, pharmacie, vêtements, électronique...) et absente des domaines de service pur (coiffure, salon de beauté) — cohérent avec le fait qu'Inventory ne concerne que `Product`.

## 17. Recherche, pagination, filtre stock faible

`GET /inventory?search=riz` (nom du produit, même convention que Catalog), `?low_stock=true` (`quantity <= minimum_quantity`, uniquement parmi les stocks ayant un seuil défini), `?per_page=` (défaut 20, plafond 100). La liste n'énumère que les `Stock` déjà enregistrés (§9) — un produit jamais approvisionné n'y figure pas encore.

## 18. Ce qui est volontairement reporté

- `transfer_in`/`transfer_out` (transferts inter-boutiques) — non demandés par cette phase.
- `unit_cost` sur `StockMovement` (coût moyen pondéré, présent dans la conception d'origine) — utile seulement avec un module Achats/Suppliers qui n'existe pas encore ; ajouté quand ce besoin sera réel, pas par anticipation.
- Notifications de stock faible (SMS, push) — uniquement la donnée (`is_low_stock`) est exposée, aucune alerte automatique.
- Toute intégration réelle avec `Sales`, `CashRegister`, `Cart`, `Payments`, achats/commandes fournisseur — hors périmètre explicite de cette phase.
- Commande artisan de réconciliation `SUM(stock_movements.quantity)` vs `stocks.quantity` (mentionnée dans la conception d'origine comme filet de sécurité) — non implémentée, à considérer si une divergence est un jour constatée en production.
