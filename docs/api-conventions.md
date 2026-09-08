# Conventions API

## 1. URLs

> **Mise à jour (2026-09-08)** : le préfixe de version `/v1/` a été retiré à la demande explicite du produit (`routes/api.php` ne l'ajoute plus). Toutes les routes sont donc sous `/api/...` directement, sans segment de version. Si un besoin de versioning apparaît plus tard (changement incompatible sur un endpoint existant), il faudra soit réintroduire un préfixe, soit versionner autrement (en-tête `Accept`, ou un préfixe appliqué uniquement à l'endpoint concerné) — décision à reprendre à ce moment-là plutôt que de la rouvrir maintenant sans besoin concret.

- Ressources tenant-scopées : `/api/stores/{store}/{resource}` (ex: `/api/stores/{store}/products`).
- Ressources non scopées à une boutique : `/api/businesses/{business}/...` (ex: abonnement), `/api/me/...` (profil, liste de mes boutiques), `/api/admin/...` (administration plateforme, voir [permissions.md](permissions.md) §6).
- Pluriel pour les collections, verbes HTTP pour l'action — pas de verbe dans l'URL (`POST /sales/{sale}/refund` est une exception assumée pour une action qui n'est pas un simple update de ressource, plutôt qu'un `PATCH` avec un champ `action=refund` ambigu).

## 2. Méthodes et codes HTTP

| Méthode | Usage | Code succès |
|---|---|---|
| GET | Lecture (liste ou détail) | 200 |
| POST | Création, ou action non idempotente (`/sales/{sale}/refund`) | 201 (création) / 200 (action) |
| PUT/PATCH | Mise à jour complète/partielle | 200 |
| DELETE | Suppression (soft delete pour les entités auditées) | 200 ou 204 |

Codes d'erreur standard : `401` non authentifié, `403` authentifié mais non autorisé (permission, feature désactivée, ou hors tenant), `404` ressource inexistante, `422` validation, `429` rate limiting, `500` erreur serveur non anticipée (toujours loguée, jamais de stacktrace exposée en réponse).

## 3. Format de réponse standard

Succès :
```json
{
  "success": true,
  "data": { "...": "..." },
  "message": null,
  "meta": {}
}
```

Collection paginée : `data` est un tableau, `meta` porte `current_page`, `per_page`, `total`, `last_page` (format natif du paginator Laravel, ré-enveloppé).

Erreur :
```json
{
  "success": false,
  "message": "Validation failed",
  "code": "VALIDATION_FAILED",
  "errors": { "name": ["Le champ name est obligatoire."] }
}
```

Le champ `code` (machine-readable, ex: `FEATURE_DISABLED`, `SUBSCRIPTION_LIMIT_EXCEEDED`, `NOT_STORE_MEMBER`) permet au frontend de distinguer les cas sans parser `message` (qui, lui, est destiné à l'affichage humain et peut être traduit). Implémenté par `App\Shared\Http\Responses\ApiResponse` (déjà en place) et branché sur les exceptions globales dans `bootstrap/app.php` (`ValidationException`, `AuthenticationException`, `AuthorizationException`, `ModelNotFoundException` déjà mappées).

## 4. Pagination, filtres, tri, recherche

Pagination par défaut : `?page=1&per_page=20` (max `per_page` à définir par ressource, ex: 100, pour éviter un déni de service par pagination excessive).

Convention de filtre : `?filter[status]=active&filter[category_id]=3`. Recherche texte : `?search=riz`. Tri : `?sort=-created_at` (préfixe `-` = descendant), `?sort=name`. Ces conventions seront implémentées via un helper partagé (`Shared/Support`, à écrire au premier module qui en a besoin) plutôt que réinventées module par module.

## 5. Validation

Toute entrée passe par un `FormRequest` dédié (`Http/Requests/` du module), jamais de validation inline dans le controller au-delà de cas triviaux. Le `FormRequest::authorize()` peut déléguer à une Policy pour éviter de dupliquer la logique d'autorisation entre validation et policy.

## 6. Authentification et autorisation

- Sanctum : token personnel émis à la connexion (`POST /api/auth/login`), envoyé en `Authorization: Bearer <token>`. Pas de cookies de session pour l'API mobile (le mode "SPA stateful" de Sanctum via `EnsureFrontendRequestsAreStateful` reste disponible pour un futur client web même origine, déjà activé dans le groupe de middleware `api`).
- Autorisation : voir [permissions.md](permissions.md) et [multi-tenancy.md](multi-tenancy.md) pour l'empilement middleware `auth:sanctum` → `store` → `feature:*` → `permission:*` → Policy.

## 7. Documentation OpenAPI

Recommandation : générer la spec OpenAPI depuis les annotations/attributs PHP au fil de l'implémentation (ex: `dedoc/scramble` qui infère beaucoup depuis les FormRequests/Resources Laravel sans annotations manuelles lourdes, ou `l5-swagger`/annotations si l'équipe préfère un contrôle explicite). Décision différée à l'implémentation du premier module métier — ne pas installer d'outil de doc avant d'avoir un premier endpoint réel à documenter, pour valider le choix sur un cas concret plutôt qu'en théorie.
