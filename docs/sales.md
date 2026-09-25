# Sales (Phase 4.3)

Module `app/Modules/Sales/`. Panier → Checkout → Vente finalisée, orchestrant Catalog, Customers, Inventory et CashRegister sans jamais dupliquer leur logique.

> **Écart d'architecture assumé, même logique qu'en Phase 4.1/4.2** : `docs/database.md` §7 avait retenu `SaleItem.sellable_type/sellable_id` (morph vers `Product` **ou** `Service`) ; le prompt de cette phase liste explicitement `SaleItem.product_id` (Product uniquement, pas de morph). Suivant la même décision déjà actée en Phase 4.1 (Inventory) et confirmée en Phase 4.2 (CashRegister) — la demande explicite de la phase l'emporte sur la conception antérieure, documentée ici plutôt que silencieusement contredite. Justifié aussi par le fond : `pricing_mode`/détail-gros n'existent que sur `Product` ; vendre un `Service` via ce module n'aurait aucun mode de prix à choisir. La vente de services (Appointments/Orders) reste hors périmètre, à concevoir séparément le moment venu. `docs/database.md` §7 est mis à jour en conséquence.

## 1. Architecture du module

```
Catalog (Product, PricingMode)
   │
   ▼
CreateSaleCheckoutRequest (validation tenant-scoped)
   │
   ▼
SaleService::checkout()  ← seul point d'écriture, transaction unique
   │
   ├── InventoryService::removeStock(..., StockMovementType::Sale, ..., reference: $sale)
   ├── CashRegisterService::recordSale($session, $total, $userId, $sale)
   │
   ▼
Sale + SaleItem[]  →  SaleResource (reçu)
```

Aucune logique métier dans `SaleController` — validation (Form Request), orchestration (Service), présentation (Resource), strictement séparés, comme partout ailleurs dans le projet.

## 2. Sale / SaleItem

`Sale` : `cash_register_id`, `cash_register_session_id`, `customer_id` nullable, `sold_by_user_id` nullable, `reference` (unique par store), `subtotal`/`discount_amount`/`total_amount` (`DECIMAL(12,2)`), `status` (`completed`/`cancelled`), `payment_method`, `idempotency_key` nullable, `sold_at`.

`SaleItem` : `product_id`, `product_name`, `pricing_mode`, `unit_price` (`DECIMAL(12,2)`), `quantity` (`DECIMAL(12,3)`), `total_amount`. `store_id` dénormalisé (même convention que `stock_movements`/`cash_movements`) pour un scoping tenant direct sans jointure.

## 3. Panier — décision d'architecture : pas de ressource persistée

Le prompt de phase laissait le choix ("Choisir UNE seule architecture et la documenter"). Décision : **aucun modèle `Cart`/`CartItem`**. Le frontend (caisse React/mobile) garde l'état du panier en mémoire locale — ajouter/retirer une ligne, changer une quantité sont des opérations purement UI, sans aller-retour serveur, jusqu'au moment du Checkout où le panier complet est soumis en une seule requête (`POST /sales/checkout`). Justifications :

- Un panier en cours de construction n'est **jamais** une donnée métier tant que le Checkout n'a pas réussi (rappel du prompt §7) — rien ne justifie de le persister avant ce moment.
- Construire `Cart`/`CartItem` (modèles, migrations, CRUD, policies, tests) pour un état intrinsèquement éphémère et propre au client aurait été la sur-architecture explicitement proscrite (§52 du prompt).
- Un seul endpoint d'écriture (`checkout`) est plus simple à rendre atomique qu'un panier persistant + un checkout séparé qui devrait re-valider un état déjà écrit ailleurs.

## 4. Checkout

`POST /api/stores/{store}/sales/checkout` :

```json
{
  "items": [{ "product_id": 12, "pricing_mode": "wholesale", "quantity": 10 }],
  "cash_register_id": 3,
  "customer_id": null,
  "payment_method": "cash",
  "discount_amount": 0,
  "idempotency_key": "abc-123"
}
```

`SaleService::checkout()` — une seule transaction :

1. Recherche `idempotency_key` (voir §8) — retourne la vente existante si trouvée, sans aucun effet de bord.
2. Résout la caisse (`cash_register_id`) et sa session ouverte (`open_session_id`) — `NoOpenCashRegisterSessionException` sinon.
3. Pour chaque ligne (triée par `product_id`, voir §9) : résout `Product` (déjà tenant-scopé par la validation), calcule le prix via `Product::priceFor(PricingMode)` — **jamais** le prix envoyé par le client (§5).
4. Calcule `subtotal`, valide la remise (§7), calcule `total`.
5. Crée `Sale` (puis sa `reference`, voir §6) et chaque `SaleItem`.
6. Pour chaque ligne : `InventoryService::removeStock($product, StockMovementType::Sale, $quantity, $userId, null, $sale)`.
7. `CashRegisterService::recordSale($session, $total, $userId, $sale)`.

Toute exception (stock insuffisant, session fermée entre-temps, etc.) fait échouer la transaction entière — `Sale`/`SaleItem` ne sont jamais persistés si une étape ultérieure échoue, aucun stock n'est décrémenté partiellement, aucun encaissement partiel n'est enregistré. Testé explicitement (`test_a_failing_sale_does_not_partially_decrement_stock_or_cash`).

## 5. Calcul des prix — le serveur, jamais le client

Le frontend envoie `product_id` + `pricing_mode` + `quantity`. Le prix unitaire vient exclusivement de `Product::priceFor(PricingMode)` (Catalog, Phase 3) — cette méthode lève déjà une exception si le mode demandé n'est pas activé (`retail_enabled`/`wholesale_enabled`), retraduite ici en `PricingModeNotAvailableException` → `422 PRICING_MODE_NOT_AVAILABLE`. Aucun prix, aucun total, n'est jamais accepté du payload.

## 6. Référence (reçu)

`reference` = `VTE-{Ymd}-{id zero-paddé sur 6 chiffres}`, généré après l'insertion de `Sale` (a besoin de son propre `id`), dans la même transaction. Choix pragmatique : pas de compteur séquentiel par store (aurait demandé une table de séquence + verrouillage dédié pour un besoin purement cosmétique) — l'unicité réelle vient de `id` (garanti unique), pas du format lisible de la référence. `unique(store_id, reference)` en base documente l'intention sans en dépendre pour la garantie d'unicité.

## 7. Remise

`discount_amount`, remise globale sur la vente, validée côté serveur : `discount_amount <= subtotal` (calculé à partir des lignes, jamais celui envoyé par le client) → `InvalidDiscountException` → `422 INVALID_DISCOUNT` sinon. Un `total` négatif est donc structurellement impossible.

## 8. Idempotence

`idempotency_key` (facultatif, string) — si fourni et déjà associé à une vente existante pour ce store, `checkout()` retourne cette vente **sans ré-exécuter aucun effet de bord** (pas de second décrément de stock, pas de second encaissement) : `200`, pas `201`. Contrainte DB `unique(store_id, idempotency_key)` (nullable — plusieurs checkouts sans clé sont tous distincts) en filet de sécurité contre une course entre deux requêtes concurrentes portant la même clé : la seconde, si elle passe la vérification applicative avant que la première ait commité, échoue à l'insertion et récupère la vente déjà créée au lieu de remonter une erreur. Champ de corps de requête (pas un header `Idempotency-Key`) — écart mineur et délibéré par rapport à l'exemple du prompt, pour rester cohérent avec le fait qu'aucun autre endpoint du projet ne lit d'en-tête métier personnalisé ; toute validation passe par les Form Requests.

## 9. Verrouillage et ordre des verrous

`InventoryService::removeStock()` verrouille la ligne `Stock` du produit ; `CashRegisterService::recordSale()` (via `recordMovement()`) verrouille la ligne `CashRegisterSession`. `SaleService::checkout()` appelle toujours Inventory **avant** CashRegister, dans cet ordre fixe pour chaque requête — deux checkouts concurrents ne peuvent donc jamais se bloquer mutuellement en verrouillant ces deux familles de ressources dans un ordre différent. Pour un panier à plusieurs produits, les lignes sont triées par `product_id` avant la boucle qui appelle `removeStock()` (`priceLines()`) : deux checkouts concurrents partageant des produits communs acquièrent donc toujours leurs verrous `Stock` dans le même ordre, ce qui élimine le risque de deadlock entre eux également.

Chaque appel de service (`removeStock`, `recordSale`) garde sa propre `DB::transaction()` interne ; imbriquée dans la transaction englobante de `checkout()`, Laravel la traite comme un `SAVEPOINT` — un rollback de la transaction englobante annule tout, y compris ce que ces appels imbriqués ont écrit.

## 10. Intégration Inventory

`InventoryService::removeStock()` et `applyDelta()` ont été étendus (paramètre `?Model $reference = null`, rétrocompatible — aucun appelant existant cassé) pour que le `StockMovement` produit porte `reference_type`/`reference_id` vers la `Sale`. `Sale` est enregistrée dans le morph map (`SalesServiceProvider::boot()`, `'sale' => Sale::class`) — jamais de nom de classe complet stocké.

## 11. Intégration CashRegister

`CashRegisterService::recordSale(CashRegisterSession, string $amount, ?int $userId, Model $reference)` — nouvelle méthode publique dédiée plutôt que de surcharger `cashIn()` avec un paramètre de type : `cashIn()` garde un contrat honnête ("toujours un cash-in manuel"), `recordSale()` produit un mouvement `type=sale` en passant par le même `recordMovement()` privé (mêmes verrous, même calcul de solde). Léger écart de formulation par rapport au prompt ("utiliser CashRegisterService::cashIn()") — l'intention (passer par CashRegisterService, jamais écrire `cash_movements` directement) est respectée à la lettre, seul le nom de la méthode diffère, par cohérence avec le style déjà établi dans `InventoryService` (méthodes nommées par intention plutôt qu'un paramètre `$type` sur une méthode générique).

## 12. Customer nullable

`customer_id` absent du payload → vente anonyme, cas normal (pas un cas d'erreur). Jamais de `Customer::create()` implicite. Si fourni, validé tenant-scopé (`TenantScopedRules::existsInCurrentStore('customers')`) — un client d'un autre store est rejeté en `422` à la validation, avant même d'atteindre le service.

## 13. Multi-tenancy

`product_id`, `customer_id`, `cash_register_id` tous validés tenant-scopés dans `CreateSaleCheckoutRequest` (jamais un `exists:table,id` nu). `{sale}` en lecture (`GET /sales/{sale}`) passe par le scoped route binding habituel (`Store::sales()`, nouvelle relation). Testé explicitement à chaque niveau (produit, client, caisse, vente elle-même).

## 14. Permissions

`sales.view`, `sales.create` — strictement les deux minimums demandés (§18 du prompt). Pas de `sales.cancel` : l'annulation n'est pas implémentée cette phase (nécessiterait une logique de reversal stock/caisse non construite), et une permission pour une action inexistante serait du code mort.

| Rôle | Accès |
|---|---|
| owner / admin / manager | `view` + `create` |
| cashier | `view` + `create` — confirmé littéralement par `docs/permissions.md` §3 ("Caissier : sales.create, sales.view, ...") |
| employee | `view` uniquement |

## 15. FeatureGate

Aucune nouvelle Feature : `sales` existait déjà depuis la Phase 2. Toutes les routes sous `feature:sales`.

## 16. Routes

```
GET  /api/stores/{store}/sales                 (?search=&from=&to=&payment_method=&status=&per_page=)
POST /api/stores/{store}/sales/checkout
GET  /api/stores/{store}/sales/{sale}
```

Pas de `PUT`/`DELETE` — une vente est immuable une fois créée (comme les ledgers Inventory/CashRegister), et l'annulation n'est pas de ce périmètre.

## 17. Erreurs métier

| Code | Situation |
|---|---|
| `NO_OPEN_CASH_REGISTER_SESSION` | la caisse choisie n'a pas de session ouverte |
| `PRICING_MODE_NOT_AVAILABLE` | le mode détail/gros demandé n'est pas activé pour ce produit |
| `INVALID_DISCOUNT` | la remise dépasse le sous-total |
| `INSUFFICIENT_STOCK` | (réutilisée d'Inventory) stock disponible insuffisant |

Toutes en `422`, jamais une `500`.

## 18. Arrondi

`bcmul`/`bcadd`/`bcsub` **tronquent** au lieu d'arrondir. `App\Modules\Sales\Support\Money::round()` implémente l'arrondi-au-plus-proche standard en bcmath pur (ajoute une demi-unité puis tronque) — utilisé pour chaque total de ligne et la remise, sans jamais passer par un cast `float`.

## 19. Ce qui est volontairement laissé de côté

- Paiement Mobile Money, carte, PayPlus, Stripe, paiement mixte réel — `PaymentMethod` prépare l'enum, seul `cash` est accepté par le Checkout.
- Annulation/remboursement — aucune logique de reversal stock/caisse dans cette phase.
- Vente de `Service` — hors périmètre (voir la note de révision en tête de ce document).
- Cart persistant côté backend — décision explicite, voir §3.
- Remises par ligne, coupons, promotions — seule une remise globale existe.
- Génération PDF/impression — `SaleResource` fournit les données, pas le rendu.
