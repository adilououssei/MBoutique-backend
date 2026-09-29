# CashRegister (Phase 4.2)

Module `app/Modules/CashRegister/`. Gestion des caisses physiques d'une boutique, de leurs sessions d'utilisation, et des mouvements d'argent qui s'y produisent.

> **Écart d'architecture assumé et documenté, comme pour Inventory** : `docs/database.md` §8 avait retenu, avant cette phase, `CashRegisterSession` scopée directement au store (sous-entendu : une seule caisse par boutique) et des types de mouvement (`CashTransaction`) différents. Le prompt de la Phase 4.2 demande explicitement une entité `CashRegister` séparée (plusieurs caisses possibles par boutique) et une nomenclature de types différente. Décision : suivre la même logique déjà actée avec l'utilisateur en Phase 4.1 (Inventory) — la nouvelle demande explicite l'emporte sur la conception antérieure quand les deux divergent, et l'écart est documenté aux deux endroits plutôt que silencieusement résolu. `docs/database.md` §8 est mis à jour en conséquence.

## 1. CashRegister ≠ CashRegisterSession

```
CashRegister (le point de caisse permanent — "Caisse principale", "Caisse 2")
   │
   └── CashRegisterSession[] (une période d'utilisation : ouverture → fermeture)
           │
           └── CashMovement[] (historique append-only de cette session)
```

Une boutique peut avoir plusieurs `CashRegister`. Chaque `CashRegister` accumule un historique de `CashRegisterSession` dans le temps (un service du matin, un de l'après-midi, etc.) — jamais réutilisées, chaque ouverture crée une nouvelle session.

## 2. CashRegister

| Champ | Règle |
|---|---|
| `nom` | requis |
| `code` | nullable, unique par store (pas globalement) |
| `actif` | défaut `true` — voir §12 |
| `session_ouverte_id` | pointeur interne, jamais exposé en écriture — voir §3 |

Pas de suppression physique (`DELETE`) — une caisse ayant un historique de sessions ne doit jamais perdre cet historique. `actif=false` la désactive sans y toucher.

## 3. Une seule session ouverte — garantie applicative et DB

`caisses.session_ouverte_id` (nullable, **unique**, sans contrainte FK — voir §4) est l'unique pointeur vers "la session actuellement ouverte de cette caisse". Une caisse ne peut structurellement pointer que vers **une** session à la fois : ce n'est pas une contrainte ajoutée après coup sur `CashRegisterSession.statut`, c'est la forme même du schéma qui rend "deux sessions ouvertes pour la même caisse" impossible à représenter. `CashRegisterService::openSession()` verrouille la ligne `CashRegister` (`lockForUpdate()`) avant de vérifier `session_ouverte_id === null`, à l'intérieur d'une transaction — la seconde tentative concurrente d'ouverture attend que la première commite, puis échoue proprement (`422 CAISSE_DEJA_OUVERTE`).

`CashRegisterSession.statut` (`ouverte`/`fermee`) reste une colonne à part entière — utile pour l'historique/le filtrage — maintenue en cohérence avec `session_ouverte_id` par le même service, jamais par un autre chemin d'écriture.

## 4. Pourquoi `session_ouverte_id` n'a pas de contrainte FK

`caisses` référence `sessions_caisse`, qui elle-même référence `caisses` (`caisse_id`) — dépendance circulaire entre les deux tables. Plutôt qu'une troisième migration `ALTER TABLE` pour ajouter la FK après coup, `session_ouverte_id` reste un entier nullable+unique sans contrainte référentielle : le seul et unique écrivain est `CashRegisterService`, toujours sous verrou, ce qui suffit à garantir sa cohérence sans complexité migratoire supplémentaire — cohérent avec la consigne de rester pragmatique (§52 du prompt de phase).

## 5. Ouverture et premier mouvement

Décision (Phase 4.2 §16) : `montant_ouverture` **n'est pas** juste un champ sur `CashRegisterSession` pendant que le ledger démarrerait à 0 — l'ouverture crée un `CashMovement` explicite de type `ouverture` (`solde_avant=0`, `solde_apres=montant_ouverture`), exactement comme `StockMovementType::Initial` pour Inventory (`docs/inventory.md`). Une seule source de vérité : le solde se lit toujours depuis le dernier mouvement, jamais depuis un champ dupliqué.

## 6. Solde de la session — pas de champ `solde_courant` en base

Décision documentée (Phase 4.2 §15) : `CashRegisterSession` ne porte **pas** de colonne `solde_courant` — le schéma proposé par le prompt ne le liste pas, et une session a toujours au moins un mouvement (`ouverture`) dès qu'elle existe. Le solde courant se lit en O(1) : `CashMovement::where('session_caisse_id', ...)->latest('id')->value('solde_apres')`. Pas de deuxième source de vérité à synchroniser ; le ledger reste l'unique source, comme pour Inventory (`docs/inventory.md` §2).

## 7. Ledger append-only

Aucune route `PUT`/`DELETE` sur un `CashMovement` — vérifié par test (les routes n'existent tout simplement pas : 404). Une erreur constatée s'exprime comme un nouveau mouvement compensatoire (type `ajustement`), jamais comme une édition du mouvement fautif.

## 8. Types de mouvements

| Type | Créable manuellement | Notes |
|---|---|---|
| `ouverture` | non (créé par `openSession()`) | voir §5 |
| `entree` | oui | entrée manuelle |
| `sortie` | oui | sortie manuelle, refusée si elle dépasserait le solde — §9 |
| `ajustement` | oui, `motif` obligatoire | signé (positif ou négatif) — §10 |
| `vente` | **non** | réservé au futur module Sales, voir §14 |
| `remboursement` | **non** | préparé (Phase 4.2 §12 : "ne sera pas utilisé par Sales dans cette phase"), réservé de la même façon |

`CashMovementType::manuallyRecordable()` exclut `ouverture`/`vente`/`remboursement` — trois `CreateXRequest` distincts (`CashInRequest`, `CashOutRequest`, `AdjustCashRequest`), pas un unique endpoint générique avec un champ `type`, conformément à la liste de Form Requests explicitement nommée par le prompt de phase (§44).

## 9. Solde négatif interdit

`CashRegisterService` vérifie `bccomp($balanceAfter, '0', 2) < 0` **avant** d'écrire un `sortie` ou un `ajustement` négatif ; si l'opération ferait passer le solde sous zéro, `InsufficientCashException` est levée → `422 SOLDE_CAISSE_INSUFFISANT`, jamais une 500, jamais un solde négatif persisté. Même garde pour les deux (`sortie` et `ajustement`) — physiquement, un tiroir-caisse ne peut pas contenir un montant négatif, quelle que soit l'origine de la sortie.

## 10. Ajustement

`montant` est **signé** (contrairement à `entree`/`sortie`, dont `montant` est une magnitude non signée validée par `gt:0`) — un ajustement peut corriger dans les deux sens. `motif` est **obligatoire** (Phase 4.2 §19), et un montant de `0` est explicitement rejeté (ne changerait rien, n'a pas de sens dans un audit).

## 11. Fermeture

`POST .../sessions/{session}/fermer` : le client envoie uniquement `montant_fermeture_reel` (compté physiquement) et `note_fermeture` optionnel. `montant_fermeture_attendu` est **toujours calculé par le backend** (le solde courant au moment de la fermeture, §6) — jamais saisi par le client. `difference = actual - expected`, signe conservé (positif = excédent, négatif = manque, jamais transformé en valeur absolue). Verrouillage : `CashRegister` puis `CashRegisterSession` (même ordre qu'à l'ouverture, pour éviter un deadlock entre une ouverture et une fermeture concurrentes sur la même caisse). Après fermeture : `status=fermee`, `session_ouverte_id` remis à `null` sur la caisse — elle peut être rouverte (nouvelle session) mais celle-ci reste figée définitivement (§7, aucun endpoint ne permet de la rouvrir ni de la modifier).

## 12. Caisse inactive

`actif=false` empêche `openSession()` (`422 CAISSE_INACTIVE`) mais ne touche à rien d'existant — son historique de sessions/mouvements reste consultable normalement.

## 13. Concurrence

Chaque écriture financière (`openSession`, `cashIn`, `cashOut`, `adjust`, `closeSession`) s'exécute dans `DB::transaction()` avec `lockForUpdate()` sur la ligne pertinente (`CashRegister` à l'ouverture/fermeture, `CashRegisterSession` pour les mouvements pendant une session déjà ouverte) — mêmes principes qu'Inventory (`docs/inventory.md` §7).

**Limite de test assumée** (identique à Inventory, prompt §48) : SQLite in-memory (connexion unique) ne permet pas un vrai test multi-connexions, et ignore `lockForUpdate()`. `test_two_sequential_cash_outs_that_together_exceed_the_balance_cannot_both_succeed` documente cette limite et vérifie le résultat métier séquentiel que le verrouillage doit garantir une fois en production (MySQL).

## 14. Futur contrat avec Sales

Non implémenté ici. `reference_type`/`reference_id` (morph, déjà en base sur `mouvements_caisse`, jamais alimentés dans cette phase) sont prêts à recevoir une future `Sale` :

```
Sale → Payment → CashRegisterService → CashMovement(type: sale, reference: Sale)
```

Sales ne doit **jamais** faire `$session->balance += $amount` ni écrire directement dans `mouvements_caisse` — seul `CashRegisterService` écrit le ledger (Phase 4.2 §39/§40), même règle que `docs/inventory.md` §14 pour `InventoryService`.

## 15. Payment ≠ CashMovement

Cette phase ne crée ni modèle `Payment`, ni méthodes de paiement (cash/mobile money/carte). `CashRegister` gère uniquement l'argent qui entre/sort physiquement ou comptablement de la caisse — la distinction entre "comment le client a payé" (`Payment`, futur) et "ce que ça a fait au solde de la caisse" (`CashMovement`, ce module) reste entière : un futur `Sale → Payment → CashRegisterService` restera le seul chemin, `Payment` n'existe pas encore et n'est pas anticipé au-delà de cette phrase.

## 16. Isolation multi-tenant

Trois niveaux de scoped binding : `{store} → {cashRegister}` (via `Store::cashRegisters()`, nouvelle relation) `→ {session}` (via `CashRegister::sessions()`) — chaque enfant scopé à son parent immédiat, même mécanisme que Catalog/Customers/Inventory. `CashMovement` n'est jamais un paramètre de route (pas de `{movement}` — pas de show/update/delete individuel), retrouvé uniquement via `session_caisse_id` déjà validé par le binding scopé de `{session}`.

## 17. Permissions

Cinq permissions, pas plus : `caisse.voir`, `caisse.gerer`, `caisse.ouvrir`, `caisse.fermer`, `caisse.ajuster`. `docs/permissions.md` §3 avait déjà anticipé `view`/`ouverte`/`close`/`adjust` avant cette phase — `manage` (CRUD sur la définition d'une caisse) est la seule addition, justifiée par le besoin explicite du prompt (§29 : créer/modifier une caisse) que la liste d'origine n'avait pas anticipé (même précédent que `produits.importer` en Phase 3).

| Rôle | Accès |
|---|---|
| owner / admin / manager | `view` + `manage` + `ouverte` + `close` + `adjust` (accès complet) |
| **cashier** | `view` + `ouverte` + `close` + `adjust` — **pas** `manage` |
| employee | `view` uniquement |

Le cas du caissier mérite une note : `docs/permissions.md` §3 dit littéralement *"Caissier : ..., cash_register.\*, ..."* — un wildcard écrit avant que `manage` existe. Lu aujourd'hui, `manage` est une tâche de configuration (définir les caisses physiques d'une boutique), pas une opération quotidienne de caissier (ouvrir/fermer son propre service, faire une entrée/sortie) — le caissier garde donc les quatre permissions opérationnelles que la note anticipait explicitement, pas celle qu'elle n'avait jamais envisagée.

## 18. FeatureGate

Aucune nouvelle Feature : `caisse` existait déjà depuis la Phase 2 (`FeatureSeeder`), incluse dans la quasi-totalité des domaines. Toutes les routes du module sont sous `feature:caisse`.

## 19. Ce qui est volontairement reporté

- `Sale`, `SaleItem`, `Cart`, `Checkout`, `Payment`/`PaymentMethod` (cash/mobile money/carte), `Receipt` — hors périmètre explicite de cette phase.
- Intégration réelle Inventory ↔ CashRegister — les deux modules restent indépendants ; un futur `Sales` orchestrera les deux dans sa propre transaction (Phase 4.2 §46).
- Mécanisme d'audit/correction d'une session déjà fermée — explicitement exclu (§25 du prompt), à concevoir séparément si un besoin réel apparaît.
- Table `CashTransaction`/nomenclature de types de la conception d'origine (`sale_in`, `refund_out`, `expense_out`, `deposit_in`, `withdrawal_out`) — remplacée par `CashMovementType` (§8), voir la note de révision en tête de ce document et dans `docs/database.md` §8.
