# Users (Phase 1)

## Décision assumée : `User` reste dans `app/Models/`, pas dans `app/Modules/Users/`

`docs/modules.md` assigne la responsabilité de l'entité `User` au module `Users`. En pratique, `User` est aussi **le modèle d'authentification Laravel** (référencé par `config/auth.php`, `Laravel\Sanctum\HasApiTokens`, `Spatie\Permission\Traits\HasRoles`, le système de notifications, et potentiellement d'autres packages qui supposent `App\Models\User` par convention). Le déplacer physiquement dans `app/Modules/Users/Models/User.php` n'apporterait aucun bénéfice fonctionnel et casserait des conventions Laravel/écosystème pour un gain purement symbolique.

**Décision** : `User` reste à l'emplacement standard Laravel `app/Models/User.php`. Le module `Users` (`app/Modules/Users/`) porte tout ce qui est spécifique au *profil* utilisateur (pour l'instant : `UserResource`), pas le modèle Eloquent lui-même. Ceci est une exception pragmatique documentée, pas une remise en cause de l'architecture modulaire — voir aussi `docs/audit-2026-09.md` §11 sur le pragmatisme attendu pour une équipe de 2 développeurs.

## Attributs du modèle `User`

| Colonne | Type | Contrainte |
|---|---|---|
| `name` | string | requis |
| `email` | string | requis, **unique globalement** (identifiant de connexion) |
| `phone` | string, nullable | **unique si renseigné** (NULL multiples autorisés) — utile pour une identification future par mobile money/OTP, non exigé à l'inscription |
| `password` | string (hashed) | requis, jamais exposé (cast `hashed`, attribut `#[Hidden]`) |
| `status` | string, cast `App\Enums\UserStatus` | `active` par défaut ; `inactive` bloque la connexion (voir [authentication.md](authentication.md)) |
| `email_verified_at` | timestamp, nullable | présent au schéma, non exploité cette phase (pas de flux de vérification) |
| `deleted_at` | soft delete | un `User` n'est jamais supprimé physiquement (référencé par de l'historique via `StoreUser`, `BusinessUser`, et plus tard `Sale.sold_by_user_id`, etc.) |

## Pourquoi `phone` est nullable + unique

Un utilisateur peut s'inscrire avec seulement un e-mail (cas standard). Le téléphone devient pertinent dès qu'un moyen de paiement mobile money ou une vérification par SMS est introduit — réserver la colonne et sa contrainte d'unicité maintenant évite une migration corrective plus tard, sans forcer sa saisie aujourd'hui.

## `UserResource`

Expose `id`, `name`, `email`, `phone`, `status`, `created_at`. N'expose jamais `password`, `remember_token`, ni la relation `roles`/`businessMemberships`/`storeMemberships` brute (celles-ci sont exposées, quand nécessaire, par les Resources des modules concernés — `StoreUserResource` par exemple — pour ne pas coupler `UserResource` à la structure d'autres modules).
