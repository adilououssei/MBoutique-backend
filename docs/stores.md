# Business, Store et appartenance (Phase 1)

Module `app/Modules/Tenancy/`. Voir [database.md](database.md) §2 pour le schéma, [multi-tenancy.md](multi-tenancy.md) pour la stratégie d'isolation, [permissions.md](permissions.md) §7 pour l'autorisation business-level.

## Endpoints

| Méthode | Route | Auth requise | Autorisation |
|---|---|---|---|
| GET | `/api/entreprises` | oui | liste les Business dont l'utilisateur est `BusinessUser` |
| POST | `/api/entreprises` | oui | tout utilisateur authentifié peut créer un Business (devient `proprietaire`) |
| GET | `/api/entreprises/{business}` | oui | `BusinessPolicy::view` — membre (tout rôle) |
| POST | `/api/entreprises/{business}/utilisateurs` | oui | `BusinessPolicy::manageMembers` — `proprietaire` uniquement |
| GET | `/api/entreprises/{business}/boutiques` | oui | `BusinessPolicy::view` |
| POST | `/api/entreprises/{business}/boutiques` | oui | `BusinessPolicy::createStore` — `proprietaire` ou `administrateur` |
| GET | `/api/boutiques` | oui | aucune (retourne les stores de l'appelant lui-même) — voir §"Store switching" |
| GET | `/api/boutiques/{store}` | oui | middleware `store` (membre actif) puis `StorePolicy::view` |
| GET | `/api/boutiques/{store}/membres` | oui | middleware `store` puis permission `membres.voir` |
| POST | `/api/boutiques/{store}/membres` | oui | middleware `store` puis permission `membres.gerer` |
| DELETE | `/api/boutiques/{store}/membres/{user}` | oui | middleware `store` puis permission `membres.gerer` |

## Store switching — décision confirmée

L'architecture déjà validée (`docs/multi-tenancy.md` §5) imposait `{store}` dans l'URL comme unique mécanisme d'identification du tenant courant, sans état côté serveur. Cette phase confirme ce choix plutôt que d'en explorer d'autres (header, session) : `GET /api/boutiques` retourne la liste des boutiques actives de l'utilisateur (avec son rôle sur chacune, résolu via `StoreController::roleOnStore()`), et c'est au client (mobile/React) de mémoriser la boutique sélectionnée et de l'inclure dans chaque URL suivante. Le backend ne fait confiance à aucun état de sélection côté client au-delà de cette vérification par requête.

## Provisionnement automatique des accès (implémenté cette phase)

Conforme à `docs/database.md` §2 :

1. **Création d'un Business** (`BusinessService::createForOwner`) : crée le `Business` + un `BusinessUser(role: owner)` pour le créateur, dans une transaction.
2. **Création d'un Store** (`StoreService::createForBusiness`) : crée le `Store`, provisionne les 5 rôles spatie du template (`StoreRoleProvisioner`), puis crée un `StoreUser(active)` + assigne le rôle store correspondant pour **chaque** `BusinessUser` déjà existant du Business — dans la même transaction.
3. **Ajout d'un `BusinessUser` à un Business qui a déjà des Stores** (`BusinessUserController` + `StoreService::grantAccessToAllStores`) : symétrique du point 2, pour que l'accès à un Business ne dépende jamais de l'ordre de création (Store d'abord vs BusinessUser d'abord).

## Rôles store-level provisionnés par défaut

`App\Modules\Authorization\Support\StoreRole` : `proprietaire`, `administrateur`, `gerant`, `caissier`, `employe`. Permissions accordées cette phase (`membres.voir`, `membres.gerer`) — voir `StoreRole::defaultPermissions()`. Ce sont des rôles **modèles**, librement modifiables ensuite par le propriétaire de la boutique (spatie ne restreint pas leur édition) ; le code ne teste jamais `if ($role === 'gerant')`, uniquement des permissions.

## `domaine_activite_id` — absent de `boutiques` (rappel assumé)

Comme documenté avant l'implémentation, `boutiques.domaine_activite_id` n'existe pas encore : le module `Features`/`BusinessDomain` est Phase 2. La colonne sera ajoutée par une migration dédiée à ce moment, pas en modifiant celle-ci.

## Décision finale : 404 pour tout accès non autorisé à un Store

`docs/multi-tenancy.md` laissait le choix 403 vs 404 ouvert. Décision prise pendant l'implémentation : **toujours 404**, qu'il s'agisse d'un Store inexistant, d'un utilisateur jamais invité, ou d'un membre révoqué — aucune de ces situations ne doit être distinguable de l'extérieur. Implémenté dans `ResolveStoreContext`.

## Bug trouvé et corrigé pendant cette phase

`Business::create()`/`Store::create()` échouaient avec une violation de contrainte `NOT NULL` sur `proprietaire_id`/`entreprise_id`. Cause : ces colonnes sont délibérément absentes de `$fillable` (elles ne doivent jamais provenir d'un input client), donc silencieusement ignorées par le mass assignment lors de la création par le service lui-même. Corrigé en construisant le modèle avec les données validées puis en fixant la valeur serveur via `forceFill()` avant `save()` — le mécanisme correct pour qu'un service de confiance renseigne un champ volontairement non fillable, sans l'ouvrir au mass assignment côté client. Voir `BusinessService::createForOwner` et `StoreService::createForBusiness`.

## Second bug, plus critique : `StoreTeamResolver` ne pouvait pas être instancié

Trouvé en tentant de provisionner le premier rôle spatie. `Spatie\Permission\PermissionRegistrar` instancie la classe configurée dans `permission.team_resolver` avec un `new $class` **sans passer par le conteneur Laravel** (`PermissionRegistrar.php:60`). `StoreTeamResolver` avait été conçu (lors de la phase de conception) avec `TenantContextContract` en injection de constructeur — ce qui aurait fait planter l'application dès le premier accès à une permission, en production comme en test. Corrigé en supprimant l'injection de constructeur et en résolvant `TenantContextContract` via le helper `app()` à l'intérieur de chaque méthode (`app/Shared/Tenancy/StoreTeamResolver.php`). Vérifié explicitement en isolation avant de poursuivre l'implémentation. C'est le seul point où l'implémentation a dû corriger un choix technique de la phase de conception précédente plutôt que se contenter de l'appliquer — documenté ici et dans `docs/permissions.md` §5 bis conformément à la consigne de signaler toute incompatibilité technique réelle rencontrée.
