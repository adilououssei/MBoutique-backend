# Reports module

Read-only aggregation and analytics over the other modules' data. Owns saved report presets, not primary business data.

See `docs/modules.md` for the standard internal folder anatomy (Http/Controllers, Http/Requests, Http/Resources, Models, Policies, Providers, Routes, Database/Migrations, Database/Factories, Tests) applied to every module, and `docs/database.md` for this module's entities.

## Tableau de bord (implémenté le 2026-10-04)

`GET /api/boutiques/{boutique}/rapports/tableau-de-bord?periode=aujourdhui|7_jours|30_jours|ce_mois` (défaut : `aujourdhui`).

Chaîne : `auth:sanctum` → `store` → `feature:rapports` → `permission:rapports.voir`. Permission accordée par défaut au propriétaire, à l'administrateur et au gérant ; ni au caissier ni à l'employé (le bénéfice estimé révèle les prix d'achat). La migration `2026_10_04_000001_grant_reports_permission_to_existing_store_roles` l'ajoute aux boutiques créées avant ce module, sans toucher aux autres permissions.

Réponse (`donnees`) :

| Clé | Contenu |
|---|---|
| `periode` | `code`, `libelle`, `du`, `au` (ISO 8601 dans le fuseau de la boutique), `fuseau_horaire` |
| `resume` | `chiffre_affaires`, `nombre_ventes`, `panier_moyen`, `articles_vendus`, `remises`, `benefice_estime` (null si aucun produit vendu n'a de prix d'achat), `produits_sans_prix_achat` |
| `periode_precedente` | mêmes indicateurs principaux sur la période de comparaison |
| `evolution` | variation en % (1 décimale) par indicateur ; `null` si la période précédente vaut 0 |
| `courbe` | `granularite` (`heure` : 24 points pour `aujourdhui`, `jour` sinon) et `points[]` (`cle`, `libelle`, `chiffre_affaires`, `nombre_ventes`) |
| `meilleurs_produits` | 5 produits au plus grand chiffre d'affaires |
| `modes_paiement` | montant et nombre de ventes par mode |

### Périodes

- Les bornes sont calculées dans le fuseau de la boutique (`boutiques.fuseau_horaire`) : une « journée » est celle du commerçant, pas celle d'UTC. La courbe est donc découpée en PHP plutôt qu'avec `DATE()`/`HOUR()` SQL (fuseau + fonctions différentes entre MySQL et SQLite).
- La période en cours s'arrête à l'instant présent. La comparaison porte sur une **durée écoulée identique** : à 10 h, « aujourd'hui » est comparé à hier 0 h–10 h, pas à toute la journée d'hier.
- Seules les ventes `terminee` comptent.

### Bénéfice estimé

`Σ (montant_ligne − quantité × prix_achat)` sur les lignes dont le produit a un prix d'achat, moins les remises de la période. Estimation : `prix_achat` est le prix **actuel** du produit (non figé sur la ligne de vente). Le figer au moment de la vente est la prochaine amélioration naturelle.
