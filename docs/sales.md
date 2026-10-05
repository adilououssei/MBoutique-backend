# Sales (Phase 4.3)

Module `app/Modules/Sales/`. Panier → Checkout → Vente finalisée, orchestrant Catalog, Customers, Inventory et CashRegister sans jamais dupliquer leur logique.

> **Écart d'architecture assumé, même logique qu'en Phase 4.1/4.2** : `docs/database.md` §7 avait retenu `SaleItem.sellable_type/sellable_id` (morph vers `Product` **ou** `Service`) ; le prompt de cette phase liste explicitement `SaleItem.produit_id` (Product uniquement, pas de morph). Suivant la même décision déjà actée en Phase 4.1 (Inventory) et confirmée en Phase 4.2 (CashRegister) — la demande explicite de la phase l'emporte sur la conception antérieure, documentée ici plutôt que silencieusement contredite. Justifié aussi par le fond : `mode_prix`/détail-gros n'existent que sur `Product` ; vendre un `Service` via ce module n'aurait aucun mode de prix à choisir. La vente de services (Appointments/Orders) reste hors périmètre, à concevoir séparément le moment venu. `docs/database.md` §7 est mis à jour en conséquence.

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

`Sale` : `caisse_id`, `session_caisse_id`, `client_id` nullable, `vendeur_id` nullable, `reference` (unique par store), `sous_total`/`montant_remise`/`montant_total` (`DECIMAL(12,2)`), `statut` (`terminee`/`annulee`), `mode_paiement`, `cle_idempotence` nullable, `vendue_le`.

`SaleItem` : `produit_id`, `nom_produit`, `mode_prix`, `prix_unitaire` (`DECIMAL(12,2)`), `quantite` (`DECIMAL(12,3)`), `montant_total`. `boutique_id` dénormalisé (même convention que `mouvements_stock`/`mouvements_caisse`) pour un scoping tenant direct sans jointure.

## 3. Panier — décision d'architecture : pas de ressource persistée

Le prompt de phase laissait le choix ("Choisir UNE seule architecture et la documenter"). Décision : **aucun modèle `Cart`/`CartItem`**. Le frontend (caisse React/mobile) garde l'état du panier en mémoire locale — ajouter/retirer une ligne, changer une quantité sont des opérations purement UI, sans aller-retour serveur, jusqu'au moment du Checkout où le panier complet est soumis en une seule requête (`POST /ventes/encaisser`). Justifications :

- Un panier en cours de construction n'est **jamais** une donnée métier tant que le Checkout n'a pas réussi (rappel du prompt §7) — rien ne justifie de le persister avant ce moment.
- Construire `Cart`/`CartItem` (modèles, migrations, CRUD, policies, tests) pour un état intrinsèquement éphémère et propre au client aurait été la sur-architecture explicitement proscrite (§52 du prompt).
- Un seul endpoint d'écriture (`checkout`) est plus simple à rendre atomique qu'un panier persistant + un checkout séparé qui devrait re-valider un état déjà écrit ailleurs.

## 4. Checkout

`POST /api/boutiques/{store}/ventes/encaisser` :

```json
{
  "lignes": [{ "produit_id": 12, "mode_prix": "gros", "quantite": 10 }],
  "caisse_id": 3,
  "client_id": null,
  "mode_paiement": "especes",
  "montant_remise": 0,
  "cle_idempotence": "abc-123"
}
```

`SaleService::checkout()` — une seule transaction :

1. Recherche `cle_idempotence` (voir §8) — retourne la vente existante si trouvée, sans aucun effet de bord.
2. Résout la caisse (`caisse_id`) et sa session ouverte (`session_ouverte_id`) — `NoOpenCashRegisterSessionException` sinon.
3. Pour chaque ligne (triée par `produit_id`, voir §9) : résout `Product` (déjà tenant-scopé par la validation), calcule le prix via `Product::priceFor(PricingMode)` — **jamais** le prix envoyé par le client (§5).
4. Calcule `sous_total`, valide la remise (§7), calcule `total`.
5. Crée `Sale` (puis sa `reference`, voir §6) et chaque `SaleItem`.
6. Pour chaque ligne : `InventoryService::removeStock($product, StockMovementType::Sale, $quantity, $userId, null, $sale)`.
7. `CashRegisterService::recordSale($session, $total, $userId, $sale)`.

Toute exception (stock insuffisant, session fermée entre-temps, etc.) fait échouer la transaction entière — `Sale`/`SaleItem` ne sont jamais persistés si une étape ultérieure échoue, aucun stock n'est décrémenté partiellement, aucun encaissement partiel n'est enregistré. Testé explicitement (`test_a_failing_sale_does_not_partially_decrement_stock_or_cash`).

## 5. Calcul des prix — le serveur, jamais le client

Le frontend envoie `produit_id` + `mode_prix` + `quantite`. Le prix unitaire vient exclusivement de `Product::priceFor(PricingMode)` (Catalog, Phase 3) — cette méthode lève déjà une exception si le mode demandé n'est pas activé (`vente_detail_active`/`vente_gros_active`), retraduite ici en `PricingModeNotAvailableException` → `422 MODE_PRIX_INDISPONIBLE`. Aucun prix, aucun total, n'est jamais accepté du payload.

## 6. Référence (reçu)

`reference` = `VTE-{Ymd}-{id zero-paddé sur 6 chiffres}`, généré après l'insertion de `Sale` (a besoin de son propre `id`), dans la même transaction. Choix pragmatique : pas de compteur séquentiel par store (aurait demandé une table de séquence + verrouillage dédié pour un besoin purement cosmétique) — l'unicité réelle vient de `id` (garanti unique), pas du format lisible de la référence. `unique(boutique_id, reference)` en base documente l'intention sans en dépendre pour la garantie d'unicité.

## 7. Remise

`montant_remise`, remise globale sur la vente, validée côté serveur : `montant_remise <= sous_total` (calculé à partir des lignes, jamais celui envoyé par le client) → `InvalidDiscountException` → `422 REMISE_INVALIDE` sinon. Un `total` négatif est donc structurellement impossible.

## 8. Idempotence

`cle_idempotence` (facultatif, string) — si fourni et déjà associé à une vente existante pour ce store, `checkout()` retourne cette vente **sans ré-exécuter aucun effet de bord** (pas de second décrément de stock, pas de second encaissement) : `200`, pas `201`. Contrainte DB `unique(boutique_id, cle_idempotence)` (nullable — plusieurs checkouts sans clé sont tous distincts) en filet de sécurité contre une course entre deux requêtes concurrentes portant la même clé : la seconde, si elle passe la vérification applicative avant que la première ait commité, échoue à l'insertion et récupère la vente déjà créée au lieu de remonter une erreur. Champ de corps de requête (pas un header `Idempotency-Key`) — écart mineur et délibéré par rapport à l'exemple du prompt, pour rester cohérent avec le fait qu'aucun autre endpoint du projet ne lit d'en-tête métier personnalisé ; toute validation passe par les Form Requests.

## 9. Verrouillage et ordre des verrous

`InventoryService::removeStock()` verrouille la ligne `Stock` du produit ; `CashRegisterService::recordSale()` (via `recordMovement()`) verrouille la ligne `CashRegisterSession`. `SaleService::checkout()` appelle toujours Inventory **avant** CashRegister, dans cet ordre fixe pour chaque requête — deux checkouts concurrents ne peuvent donc jamais se bloquer mutuellement en verrouillant ces deux familles de ressources dans un ordre différent. Pour un panier à plusieurs produits, les lignes sont triées par `produit_id` avant la boucle qui appelle `removeStock()` (`priceLines()`) : deux checkouts concurrents partageant des produits communs acquièrent donc toujours leurs verrous `Stock` dans le même ordre, ce qui élimine le risque de deadlock entre eux également.

Chaque appel de service (`removeStock`, `recordSale`) garde sa propre `DB::transaction()` interne ; imbriquée dans la transaction englobante de `checkout()`, Laravel la traite comme un `SAVEPOINT` — un rollback de la transaction englobante annule tout, y compris ce que ces appels imbriqués ont écrit.

## 10. Intégration Inventory

`InventoryService::removeStock()` et `applyDelta()` ont été étendus (paramètre `?Model $reference = null`, rétrocompatible — aucun appelant existant cassé) pour que le `StockMovement` produit porte `reference_type`/`reference_id` vers la `Sale`. `Sale` est enregistrée dans le morph map (`SalesServiceProvider::boot()`, `'vente' => Sale::class`) — jamais de nom de classe complet stocké.

## 11. Intégration CashRegister

`CashRegisterService::recordSale(CashRegisterSession, string $amount, ?int $userId, Model $reference)` — nouvelle méthode publique dédiée plutôt que de surcharger `cashIn()` avec un paramètre de type : `cashIn()` garde un contrat honnête ("toujours un cash-in manuel"), `recordSale()` produit un mouvement `type=vente` en passant par le même `recordMovement()` privé (mêmes verrous, même calcul de solde). Léger écart de formulation par rapport au prompt ("utiliser CashRegisterService::cashIn()") — l'intention (passer par CashRegisterService, jamais écrire `mouvements_caisse` directement) est respectée à la lettre, seul le nom de la méthode diffère, par cohérence avec le style déjà établi dans `InventoryService` (méthodes nommées par intention plutôt qu'un paramètre `$type` sur une méthode générique).

## 12. Customer nullable

`client_id` absent du payload → vente anonyme, cas normal (pas un cas d'erreur). Jamais de `Customer::create()` implicite. Si fourni, validé tenant-scopé (`TenantScopedRules::existsInCurrentStore('clients')`) — un client d'un autre store est rejeté en `422` à la validation, avant même d'atteindre le service.

## 13. Multi-tenancy

`produit_id`, `client_id`, `caisse_id` tous validés tenant-scopés dans `CreateSaleCheckoutRequest` (jamais un `exists:table,id` nu). `{sale}` en lecture (`GET /ventes/{sale}`) passe par le scoped route binding habituel (`Store::sales()`, nouvelle relation). Testé explicitement à chaque niveau (produit, client, caisse, vente elle-même).

## 14. Permissions

`ventes.voir`, `ventes.creer` — strictement les deux minimums demandés (§18 du prompt). Pas de `ventes.annuler` : l'annulation n'est pas implémentée cette phase (nécessiterait une logique de reversal stock/caisse non construite), et une permission pour une action inexistante serait du code mort.

| Rôle | Accès |
|---|---|
| proprietaire / administrateur / gerant | `ventes.voir` + `ventes.creer` |
| caissier | `ventes.voir` + `ventes.creer` — confirmé littéralement par `docs/permissions.md` §3 ("Caissier : ventes.creer, ventes.voir, ...") |
| employe | `ventes.voir` uniquement |

## 15. FeatureGate

Aucune nouvelle Feature : `ventes` existait déjà depuis la Phase 2. Toutes les routes sous `feature:ventes`.

## 16. Routes

```
GET  /api/boutiques/{boutique}/ventes              (?recherche=&du=&au=&mode_paiement=&statut=&par_page=)
POST /api/boutiques/{boutique}/ventes/encaisser
GET  /api/boutiques/{boutique}/ventes/{vente}
```

Réponse de `encaisser` et de `GET /ventes/{vente}` (le reçu, `SaleResource`) :

```json
{
  "succes": true,
  "message": "Vente enregistrée.",
  "donnees": {
    "id": 123,
    "reference": "VTE-20260925-000123",
    "client": null,
    "vendeur": { "id": 4, "nom": "Aïcha" },
    "lignes": [
      { "produit_id": 12, "nom_produit": "Coca-Cola", "mode_prix": "detail", "quantite": "2.000", "prix_unitaire": "600.00", "total": "1200.00" }
    ],
    "sous_total": "1200.00",
    "remise": "0.00",
    "total": "1200.00",
    "mode_paiement": "especes",
    "statut": "terminee",
    "vendue_le": "2026-09-25T10:15:00.000000Z"
  },
  "meta": {}
}
```

Pas de `PUT`/`DELETE` — une vente est immuable une fois créée (comme les ledgers Inventory/CashRegister), et l'annulation n'est pas de ce périmètre.

## 17. Erreurs métier

| Code | Situation |
|---|---|
| `AUCUNE_SESSION_CAISSE_OUVERTE` | la caisse choisie n'a pas de session ouverte |
| `MODE_PRIX_INDISPONIBLE` | le mode détail/gros demandé n'est pas activé pour ce produit |
| `REMISE_INVALIDE` | la remise dépasse le sous-total |
| `STOCK_INSUFFISANT` | (réutilisée d'Inventory) stock disponible insuffisant |

Toutes en `422`, jamais une `500`.

## 18. Arrondi

`bcmul`/`bcadd`/`bcsub` **tronquent** au lieu d'arrondir. `App\Modules\Sales\Support\Money::round()` implémente l'arrondi-au-plus-proche standard en bcmath pur (ajoute une demi-unité puis tronque) — utilisé pour chaque total de ligne et la remise, sans jamais passer par un cast `float`.

## 19. Ce qui est volontairement laissé de côté

- Paiement Mobile Money, carte, PayPlus, Stripe, paiement mixte réel — `PaymentMethod` prépare l'enum, seul `especes` est accepté par le Checkout.
- ~~Annulation/remboursement~~ — livré, voir §20 (annulation totale ; le retour partiel reste à faire).
- ~~Vente de `Service`~~ — livré, voir §21.
- Cart persistant côté backend — décision explicite, voir §3.
- ~~Remises par ligne~~ — livré, voir §22. Coupons et promotions restent hors périmètre.
- Génération PDF/impression — faite côté application mobile (ticket partagé en texte ou en PDF) à partir de `SaleResource`.

## 20. Annulation d'une vente (2026-10-05)

- `POST /api/boutiques/{store}/ventes/{sale}/annuler` — corps : `motif` (obligatoire, 500 car. max), `caisse_id` (facultatif).
- **Annulation totale** : chaque ligne produit est remise en stock (`InventoryService::addStock()`, type `retour`, référence = la vente) et le total est sorti d'une session de caisse ouverte (`CashRegisterService::recordRefund()`, type `remboursement`). Une seule transaction : tout ou rien.
- Le remboursement sort de la caisse `caisse_id` si fournie, sinon de la caisse de la vente ; elle doit avoir une session **ouverte** au moment de l'annulation (pas forcément celle de la vente, qui peut être fermée).
- La vente n'est jamais supprimée : `statut = annulee`, `annulee_le`, `annulee_par_id`, `motif_annulation`, `session_remboursement_id`. `SaleResource.annulation` expose `{le, motif, par}`.
- Ordre des verrous identique au Checkout : Inventory (produits triés par id) puis CashRegister.
- Permission `ventes.annuler` : propriétaire, administrateur, gérant — **pas** le caissier. Accordée aux rôles des boutiques existantes par migration (même mécanisme que `rapports.voir`).
- Erreurs : `VENTE_DEJA_ANNULEE`, `AUCUNE_SESSION_CAISSE_OUVERTE`, `SOLDE_CAISSE_INSUFFISANT` (422).
- Les rapports ne comptent que les ventes `terminee` : une vente annulée en sort automatiquement.
- Reste à faire : retour **partiel** (rendre une partie des articles).

## 21. Vente de services

- Une ligne de `lignes` porte `produit_id` (+ `mode_prix`) **ou** `service_id`, jamais les deux (règle dans `CreateSaleCheckoutRequest::withValidator()`).
- Prix d'une ligne de service = `services.prix`, recalculé serveur. Aucun mouvement de stock.
- `lignes_vente.produit_id` et `mode_prix` deviennent nullables, `service_id` ajouté. `nom_produit` reste le libellé figé de la ligne (produit ou service) — nom de colonne historique.
- `SaleItemResource` expose `type` (`produit`|`service`) et `service_id`.
- Rapports : « meilleurs produits » ignore les lignes de service.

## 22. Remise par ligne

- `lignes.*.remise` : montant (pas un pourcentage), stocké dans `lignes_vente.montant_remise`. `montant_total` de la ligne = brut − remise.
- Une remise de ligne supérieure au brut de la ligne → `REMISE_INVALIDE`. La remise globale (`montant_remise`) s'applique ensuite sur le sous-total des lignes nettes.

## 23. Produit sans stock initialisé

- Vendre un produit dont le stock n'a jamais été initialisé renvoie désormais `422 STOCK_NON_INITIALISE` (auparavant une 500 non gérée).


## 24. Vente à crédit (2026-10-11)

`mode_paiement: credit` (exige `client_id` et la permission `credits.gerer`) avec `acompte` optionnel :

- seul l'acompte entre en caisse (`montant_acompte` sur `ventes`, null pour une vente en espèces) ; le reste (`montant_credit` dans la réponse) est inscrit au compte client (docs/customers.md §12) ;
- acompte > total → 422 `ACOMPTE_INVALIDE` ;
- l'annulation rembourse uniquement l'acompte et retire la part à crédit du compte client (si le client avait déjà remboursé, son solde devient un avoir).
