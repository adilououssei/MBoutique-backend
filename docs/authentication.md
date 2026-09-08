# Authentification (Phase 1)

Module `app/Modules/Auth/`. Mécanique pure — voir [users.md](users.md) pour l'entité `User` elle-même.

## Endpoints

| Méthode | Route | Auth | Description |
|---|---|---|---|
| POST | `/api/auth/register` | non | Crée un compte, retourne l'utilisateur + un token Sanctum |
| POST | `/api/auth/login` | non | `throttle:login` (5/min par email+IP), retourne l'utilisateur + un token |
| POST | `/api/auth/logout` | oui | Révoque uniquement le token courant (`currentAccessToken()->delete()`) |
| GET | `/api/auth/me` | oui | Retourne l'utilisateur authentifié |
| POST | `/api/auth/forgot-password` | non | Envoie un lien de réinitialisation ; répond succès que l'e-mail existe ou non (anti-énumération) |
| POST | `/api/auth/reset-password` | non | Consomme le token de réinitialisation, révoque tous les tokens Sanctum existants |
| PUT | `/api/auth/password` | oui | Change le mot de passe (exige le mot de passe actuel) |

## Décisions prises pendant l'implémentation

- **Token Sanctum "personal access token"**, pas le mode "stateful SPA" à cookies — cohérent avec un client mobile/React qui n'est pas nécessairement same-origin. Le mode stateful reste activé sur le groupe `api` (middleware `EnsureFrontendRequestsAreStateful`) pour un futur client web same-origin, sans que cela change quoi que ce soit pour le mobile.
- **Rate limiting du login** via un rate limiter nommé `login` (`AppServiceProvider::boot()`), keyed par `email+IP` plutôt que par IP seule — empêche un attaquant distribué de bloquer un compte précis tout en évitant qu'un attaquant sur une IP partagée (NAT, 4G) ne bloque des utilisateurs innocents. Réponse 429 mappée dans l'enveloppe JSON standard (`code: TOO_MANY_REQUESTS`).
- **`forgot-password` ne révèle jamais si l'e-mail existe** — toujours un message de succès générique.
- **`reset-password` révoque tous les tokens existants** de l'utilisateur (`$user->tokens()->delete()`) — un mot de passe compromis au point de nécessiter une réinitialisation doit aussi invalider toute session déjà ouverte ailleurs.
- **Compte désactivé (`status = inactive`) ne peut pas se connecter** — vérifié explicitement dans `AuthenticatedSessionController`, avant l'émission du token.
- **Email verification non implémentée** — hors périmètre explicite de cette phase (non demandé), à ajouter avec le module `Notifications` (Phase 6).

## Bug trouvé et corrigé pendant cette phase

`UserResource`/`BusinessResource`/`StoreResource` lisaient `$this->status->value` (ou `currency`/`timezone`) immédiatement après un `Model::create()`. Or Eloquent ne relit **jamais** en base les colonnes ayant une valeur par défaut SQL après un `create()` — seuls les attributs explicitement fournis (plus la clé générée) sont présents en mémoire sur l'objet retourné. Un `User`/`Business`/`Store` fraîchement créé avait donc `status === null` en mémoire, provoquant une erreur 500 sur `$this->status->value`. Corrigé en ajoutant `protected $attributes = [...]` (valeurs par défaut au niveau du modèle, en miroir exact du défaut SQL) sur les trois modèles. Voir `app/Models/User.php`, `app/Modules/Tenancy/Models/Business.php`, `app/Modules/Tenancy/Models/Store.php`. Règle à retenir pour tout futur modèle avec une colonne à défaut SQL utilisée immédiatement après création.
