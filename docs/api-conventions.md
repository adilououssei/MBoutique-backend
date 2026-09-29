# Conventions API

## 1. URLs

> **Mise à jour (2026-09-08)** : le préfixe de version `/v1/` a été retiré à la demande explicite du produit (`routes/api.php` ne l'ajoute plus). Toutes les routes sont donc sous `/api/...` directement, sans segment de version. Si un besoin de versioning apparaît plus tard (changement incompatible sur un endpoint existant), il faudra soit réintroduire un préfixe, soit versionner autrement (en-tête `Accept`, ou un préfixe appliqué uniquement à l'endpoint concerné) — décision à reprendre à ce moment-là plutôt que de la rouvrir maintenant sans besoin concret.

> **Mise à jour (2026-09-25) — nommage en français** : toute la surface de l'API (segments d'URL, champs de requête, clés JSON, valeurs d'enum, codes d'erreur, noms de permissions et de rôles) est en français, de même que les tables et colonnes — voir [database.md](database.md) §"Nommage" pour le glossaire complet. Les noms de classes PHP (`Product`, `SaleService`, ...) et les noms de paramètres de route internes (`{store}`, `{product}`, invisibles pour le client) restent en anglais, convention Laravel.

- Ressources tenant-scopées : `/api/boutiques/{boutique}/{ressource}` (ex: `/api/boutiques/12/produits`).
- Ressources non scopées à une boutique : `/api/entreprises/{entreprise}/...`, `/api/auth/moi` (profil), `/api/boutiques` (liste de mes boutiques), `/api/admin/...` (administration plateforme, voir [permissions.md](permissions.md) §6).
- Pluriel pour les collections, verbes HTTP pour l'action — pas de verbe dans l'URL, sauf pour une action métier qui n'est pas un simple update de ressource (`POST /ventes/encaisser`, `POST /caisses/{caisse}/sessions/{session}/fermer`), plutôt qu'un `PATCH` avec un champ `action=...` ambigu.

## 2. Méthodes et codes HTTP

| Méthode | Usage | Code succès |
|---|---|---|
| GET | Lecture (liste ou détail) | 200 |
| POST | Création, ou action non idempotente (`/ventes/{sale}/refund`) | 201 (création) / 200 (action) |
| PUT/PATCH | Mise à jour complète/partielle | 200 |
| DELETE | Suppression (soft delete pour les entités auditées) | 200 ou 204 |

Codes d'erreur standard : `401` non authentifié, `403` authentifié mais non autorisé (permission, feature désactivée, ou hors tenant), `404` ressource inexistante, `422` validation, `429` rate limiting, `500` erreur serveur non anticipée (toujours loguée, jamais de stacktrace exposée en réponse).

## 3. Format de réponse standard

Succès :
```json
{
  "succes": true,
  "message": null,
  "donnees": { "...": "..." },
  "meta": {}
}
```

Collection paginée : `donnees` est un tableau, `liens` et `meta` sont ajoutés. Le paginator de Laravel produit ces blocs en anglais ; `ApiResponse` les traduit à un seul endroit :

```json
{
  "succes": true,
  "message": null,
  "donnees": [ "..." ],
  "liens": { "premier": "...", "dernier": "...", "precedent": null, "suivant": "..." },
  "meta": { "page_courante": 1, "derniere_page": 3, "par_page": 20, "total": 55, "de": 1, "a": 20, "chemin": "..." }
}
```

(La liste de numéros de pages que Laravel place dans `meta.links` n'est pas exposée : `liens` et `derniere_page` suffisent à la navigation.)

Erreur :
```json
{
  "succes": false,
  "message": "Les données fournies sont invalides.",
  "code": "VALIDATION_ECHOUEE",
  "erreurs": { "nom": ["..."] }
}
```

Le champ `code` (machine-readable) permet au frontend de distinguer les cas sans parser `message` (destiné à l'affichage humain). Implémenté par `App\Shared\Http\Responses\ApiResponse` et branché sur les exceptions globales dans `bootstrap/app.php` :

| Code | HTTP | Situation |
|---|---|---|
| `VALIDATION_ECHOUEE` | 422 | validation d'un Form Request |
| `NON_AUTHENTIFIE` | 401 | jeton absent ou invalide |
| `ACCES_INTERDIT` | 403 | Policy refusée ou permission Spatie manquante |
| `INTROUVABLE` | 404 | ressource inexistante, ou appartenant à une autre boutique |
| `TROP_DE_REQUETES` | 429 | rate limiting |
| `LIMITE_ABONNEMENT_ATTEINTE` | 403 | limite du forfait (ex: nombre de boutiques) |
| `FONCTIONNALITE_DESACTIVEE` | 403 | fonctionnalité non activée pour la boutique |

Les codes métier propres à chaque module (`STOCK_INSUFFISANT`, `AUCUNE_SESSION_CAISSE_OUVERTE`, ...) sont listés dans la doc du module.

**Correction (2026-09-25)** : Laravel convertit `AuthorizationException` en `AccessDeniedHttpException` et `ModelNotFoundException` en `NotFoundHttpException` *avant* d'appeler les callbacks de rendu. Les handlers d'origine, qui ciblaient les exceptions d'origine, ne s'exécutaient donc jamais : les 403/404 renvoyaient la réponse brute de Laravel (`{"message": "..."}` en anglais). Ils ciblent désormais les exceptions HTTP réellement levées (ainsi que `UnauthorizedException` de Spatie), et toutes les erreurs passent par l'enveloppe ci-dessus.

**Limite connue** : le détail des erreurs de validation (`erreurs.nom[0]`) est produit par Laravel dans la langue de `APP_LOCALE` (actuellement `en`) — voir [roadmap.md](roadmap.md).

## 4. Pagination, filtres, tri, recherche

Pagination par défaut : `?page=1&par_page=20` (plafonné à 100 par ressource, pour éviter un déni de service par pagination excessive).

Filtres implémentés à ce jour : paramètres simples par ressource (`?actif=1`, `?categorie_id=3`, `?statut=terminee`, `?du=2026-09-01&au=2026-09-30`...). Recherche texte : `?recherche=riz`. Un helper partagé de filtre/tri générique (`Shared/Support`) reste à écrire au premier module qui en aura réellement besoin.

## 5. Validation

Toute entrée passe par un `FormRequest` dédié (`Http/Requests/` du module), jamais de validation inline dans le controller au-delà de cas triviaux. Le `FormRequest::authorize()` peut déléguer à une Policy pour éviter de dupliquer la logique d'autorisation entre validation et policy.

## 6. Authentification et autorisation

- Sanctum : token personnel émis à la connexion (`POST /api/auth/connexion`), envoyé en `Authorization: Bearer <token>`. Pas de cookies de session pour l'API mobile (le mode "SPA stateful" de Sanctum via `EnsureFrontendRequestsAreStateful` reste disponible pour un futur client web même origine, déjà activé dans le groupe de middleware `api`).
- Autorisation : voir [permissions.md](permissions.md) et [multi-tenancy.md](multi-tenancy.md) pour l'empilement middleware `auth:sanctum` → `store` → `feature:*` → `permission:*` → Policy.

## 7. Documentation OpenAPI

Recommandation : générer la spec OpenAPI depuis les annotations/attributs PHP au fil de l'implémentation (ex: `dedoc/scramble` qui infère beaucoup depuis les FormRequests/Resources Laravel sans annotations manuelles lourdes, ou `l5-swagger`/annotations si l'équipe préfère un contrôle explicite). Décision différée à l'implémentation du premier module métier — ne pas installer d'outil de doc avant d'avoir un premier endpoint réel à documenter, pour valider le choix sur un cas concret plutôt qu'en théorie.
