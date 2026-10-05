# Customers (Phase 3.5)

Module `app/Modules/Customers/`. Un annuaire client **optionnel** — voir `docs/modules.md` "Customers" pour la description d'origine du module.

## 1. Pourquoi Customers existe, et pourquoi il est optionnel

Un client identifié sert à : fidélisation, clientèle professionnelle, historique d'achats, retrouver rapidement un client en caisse, et plus tard crédit/dette ou remises personnalisées (aucun de ces usages futurs n'est implémenté ici — voir §9). Mais la règle fondamentale du projet est :

```
UNE VENTE N'A PAS BESOIN D'UN CLIENT.
```

Une boutique d'alimentation générale qui vend à des clients de passage ne doit jamais être forcée de créer une fiche client pour encaisser. `Customer` reste donc une ressource que la boutique peuple si elle en a l'usage — 0, 10 ou 10 000 clients, sans effet sur sa capacité à vendre.

## 2. Vente anonyme vs vente identifiée

Ni implémenté ni codé ici (Sales n'existe pas encore), mais c'est la contrainte de conception qui façonne tout ce module : le futur `Sale.client_id` sera **nullable**.

```json
// Sale #1001 — vente anonyme, la majorité des cas
{ "client_id": null }

// Sale #1002 — client identifié
{ "client_id": 25 }
```

Voir §8 pour le contrat complet.

## 3. Structure Customer

| Champ | Type | Règle |
|---|---|---|
| `nom` | `string` | requis — seul champ obligatoire |
| `telephone` | `string` nullable | aucun format imposé (pas seulement togolais, voir §5) |
| `email` | `string` nullable | validé comme email si renseigné |
| `nom_entreprise` | `string` nullable | client professionnel (ex: `nom` = "Restaurant La Terrasse", `nom_entreprise` = "La Terrasse SARL") |
| `adresse` | `string` nullable | |
| `notes` | `text` nullable | |
| `actif` | `bool` | défaut `true` |

Champs volontairement absents : `date_of_birth`, `gender`, `profession`, `nationality`, etc. — aucune fonctionnalité actuelle ne les utilise, et les ajouter maintenant serait de la conception spéculative.

Un commerçant peut créer un client avec seulement `{"nom": "Kossi"}` — tout le reste est optionnel, par design (§4/§8 du prompt de phase).

## 4. Duplication volontairement non empêchée

Aucune contrainte d'unicité sur `nom`, `telephone` ou `email`, ni par store ni globalement. Deux clients peuvent légitimement partager un nom, et un numéro peut être réutilisé (téléphone partagé en famille, etc.). Une détection de doublon "aide à l'utilisateur" (suggestion, pas blocage) pourra être ajoutée plus tard ; ce n'est pas dans ce périmètre.

## 5. Isolation multi-tenant

Mêmes trois couches que Catalog (`docs/catalog.md` §10), aucune nouveauté :

1. **`BelongsToStore`** sur `Customer` — `boutique_id` forcé depuis `TenantContext`.
2. **Scoped route model binding** — `Route::scopeBindings()` sur `boutiques/{store}/clients/{customer}`. Nécessite une relation `Store::customers(): HasMany` (ajoutée ici, à côté des relations `products()`/`categories()`/`services()` déjà existantes) : Laravel résout `{customer}` imbriqué via cette relation, donc un id d'un autre store 404 avant tout code applicatif.
3. **`CustomerPolicy`** — vérifie explicitement `$customer->boutique_id === $store->id` en plus de la permission spatie, comme `CategoryPolicy`.

Deux stores peuvent chacun avoir un client "Kossi" sans collision (testé).

## 6. Permissions

`customers.{view,create,update,delete}` — ajoutées à `Permissions` et à `StoreRole::defaultPermissions()`. Répartition décidée en lisant `docs/permissions.md` §3, qui l'avait déjà tranchée avant même l'implémentation : *"Caissier : ..., lecture seule sur produits/clients"*.

| Rôle | Accès clients |
|---|---|
| owner / admin / manager | CRUD complet |
| cashier / employee | lecture seule (`clients.voir`) |

Aucun nouveau rôle inventé — mapping identique à celui du Catalog (`docs/catalog.md` §12).

## 7. FeatureGate

Aucune nouvelle `Feature` créée. `clients` existait déjà depuis la Phase 2 (`FeatureSeeder`) et est incluse dans la quasi-totalité des domaines seedés (alimentation générale, boucherie, restaurant, coiffure, salon de beauté, pharmacie, vêtements, électronique, atelier, pressing, autre) — cohérent avec le fait que la gestion client est utile à presque tous les métiers, contrairement à `services` ou `tables` qui sont spécifiques. Les routes sont gardées par `feature:clients`, exactement comme le reste du catalogue est gardé par `feature:produits`/`feature:categories`/`feature:services`.

## 8. Recherche et pagination

`GET /api/boutiques/{store}/clients?recherche=...` — cherche sur `nom`, `telephone` et `nom_entreprise` (pas `email`, `adresse` ni `notes` : ce sont les trois champs par lesquels un vendeur retrouve un client en caisse, pas les recherche envisagée pour une future intégration marketing). Pagination `?par_page=` (défaut 20, plafond 100) — mêmes conventions que Catalog, rien de nouveau inventé.

## 9. Futur contrat avec Sales

Non implémenté ici. Ce que Sales devra respecter :

- `Sale.client_id` **nullable**, jamais requis.
- Le futur endpoint de création de vente doit accepter l'absence de `client_id` sans erreur — c'est le cas normal, pas une exception.
- **Ne jamais créer un `Customer` automatiquement** parce qu'une vente a eu lieu — la création d'un client reste un choix explicite du vendeur (`+ Ajouter un client`), jamais un effet de bord.
- Interface de caisse attendue (frontend, hors périmètre) : un champ Client avec `[ Aucun client ]` comme valeur par défaut, une recherche, et une option d'ajout à la volée — documentée ici pour que Sales la retrouve telle quelle le moment venu.

## 10. Suppression

`SoftDeletes` retenu (même choix que `Category`/`Product`/`Service`) : un `Customer` supprimé aujourd'hui pourrait déjà être référencé par des ventes historiques une fois `Sale.client_id` en place. Une suppression définitive casserait cet historique. Aucune contrainte FK vers `Sales` n'est créée maintenant — `Sales` n'existe pas encore — mais `Customer` est conçu compatible avec la future relation (`Sale.client_id → Customer.id`, nullable). La question d'une suppression définitive (purge RGPD, etc.) sera réévaluée à la création de `Sales`, pas anticipée ici.

## 11. Ce qui est volontairement reporté

- Crédit / dette client.
- Programme de fidélité / points.
- Remises personnalisées par client.
- Solde client (`Customer balance`).
- CRM avancé, messagerie, SMS, WhatsApp.
- Détection de doublon (name/phone/email) — non bloquante, pourra être ajoutée comme aide UI plus tard.
- Toute intégration réelle avec `Sales` (module non implémenté).

## 12. Crédit client (2026-10-11)

Le point « Crédit / dette client » du §11 est désormais livré.

- **Ledger** `mouvements_compte_client` (append-only, comme `mouvements_stock`/`mouvements_caisse`) : `vente_credit` (+), `paiement` (−), `annulation_vente` (−). Le solde n'est pas une colonne : c'est la somme des montants (positif = le client doit, négatif = avoir).
- **Écriture** uniquement via `CustomerAccountService`, qui verrouille la ligne `clients` (deux remboursements simultanés ne dépassent jamais la dette). Ordre des verrous dans une vente : Inventory → client → CashRegister.
- **API** : `GET clients/{id}/compte` (historique paginé, `meta.solde`), `POST clients/{id}/paiements` `{montant, mode: caisse|externe, caisse_id, note}`. En mode `caisse`, une entrée de caisse (`CashRegisterService::recordReceipt`) référence l'écriture. Montant > dette → 422 `PAIEMENT_INVALIDE`.
- **Liste** : chaque client porte `solde` ; `?debiteurs=1` ne garde que ceux qui doivent.
- **Permission** `credits.gerer` : propriétaire, administrateur, gérant, caissier (le remboursement se fait au comptoir). Migration d'octroi pour les boutiques existantes.
