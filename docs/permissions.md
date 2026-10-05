# Rôles et permissions

> **Mise à jour (audit architectural du 2026-09-06)** : voir [audit-2026-09.md](audit-2026-09.md). La décision "spatie/laravel-permission + teams + `boutique_id`" est **confirmée** par l'audit, avec deux précisions ajoutées : les pièges opérationnels du mode teams (§5 bis), et la séparation explicite entre autorisation **store-level** (ce document) et autorisation **business-level** (§7, nouveau — ne se recouvrent pas et ne doivent jamais être confondues).

## 1. Option évaluée : hand-rolled vs spatie/laravel-permission

| Option | Avantages | Inconvénients |
|---|---|---|
| ACL maison (tables `roles`, `permissions`, pivots faits main) | Contrôle total, zéro dépendance | Réinvente un mécanisme déjà mature ; le risque d'introduire une faille d'isolation dans un système d'ACL fait maison est justement le genre de risque à éviter sur la partie la plus sensible du projet |
| **spatie/laravel-permission, mode `teams`** | Package mature, très utilisé en production, supporte nativement le scoping par "équipe" (ici : le `Store`) via `team_foreign_key`, cache intégré, compatible Policies Laravel | Une dépendance externe de plus (acceptable : package stable, largement maintenu) |

**Décision : spatie/laravel-permission avec `teams` activé et `team_foreign_key = boutique_id`.** Déjà installé et configuré (`config/permission.php`). Chaque `Role` et chaque assignation de `Permission` est donc nativement rattachée à un `boutique_id` : un rôle "Manager" créé sur le Store 1 n'existe pas sur le Store 2, même s'ils ont le même libellé — deux lignes distinctes en base.

## 2. Comment le "team" courant est déterminé

`app/Shared/Tenancy/StoreTeamResolver.php` (déjà implémenté) relaie `TenantContext::getStoreId()` au package. Concrètement : dès que le middleware `ResolveStoreContext` (voir [multi-tenancy.md](multi-tenancy.md)) a validé l'appartenance de l'utilisateur au store demandé et appelé `TenantContext::setStoreId()`, tout appel à `$user->can('produits.voir')` ou `$user->hasRole('gerant')` est automatiquement évalué dans le contexte de ce store — aucun code métier n'a besoin de préciser explicitement le store à chaque vérification.

## 3. Rôles

Le système ne code aucun rôle "en dur" dans la logique applicative (pas de `if ($role === 'gerant')`) — tout accès se vérifie par **permission**, jamais par nom de rôle. Les rôles ne sont qu'un regroupement pratique de permissions, et une boutique peut créer ses propres rôles.

Rôles fournis comme **modèles pré-remplis** (copiés à la création d'un store, modifiables ensuite librement par le propriétaire) :

| Rôle modèle | Usage typique |
|---|---|
| Propriétaire | Toutes les permissions, y compris `parametres.manage` et gestion des autres membres |
| Administrateur | Toutes les permissions opérationnelles, hors gestion de l'abonnement |
| Manager | Gestion quotidienne (produits, stock, ventes, employés), pas les paramètres sensibles |
| Caissier | `ventes.creer`, `ventes.voir`, `caisse.*`, lecture seule sur produits/clients |
| Employé | Accès minimal (ex: consulter son planning, créer un rendez-vous) |

Le propriétaire d'un store peut renommer, dupliquer, ou créer des rôles avec n'importe quelle combinaison de permissions — l'UI d'administration des rôles n'est pas dans le périmètre de cette phase de conception mais l'API le permet nativement (spatie expose `Role::create()`, `assignPermission()` sans restriction sur la combinaison).

## 4. Nomenclature des permissions

Convention : `<module>.<action>`, module au pluriel (ou nom de domaine : `stock`, `caisse`), action en français à l'infinitif (`voir`, `creer`, `modifier`, `supprimer`, `gerer`...). Liste de départ (extensible sans migration de schéma — une permission est juste une ligne, ajoutée par seeder à mesure que les modules sont implémentés). Celles déjà implémentées sont définies dans `App\Modules\Authorization\Support\Permissions` :

```
produits.voir         produits.creer        produits.modifier     produits.supprimer    produits.importer
categories.voir       categories.creer      categories.modifier   categories.supprimer
services.voir         services.creer        services.modifier     services.supprimer
stock.voir            stock.ajuster         stock.inventorier
ventes.voir           ventes.creer          ventes.annuler         ventes.rembourser
caisse.voir           caisse.gerer          caisse.ouvrir          caisse.fermer          caisse.ajuster
clients.voir          clients.creer         clients.modifier      clients.supprimer
fournisseurs.voir     fournisseurs.creer    fournisseurs.modifier fournisseurs.supprimer
employes.voir         employes.gerer
rendez_vous.voir      rendez_vous.creer     rendez_vous.modifier  rendez_vous.annuler
commandes.voir        commandes.creer       commandes.modifier    commandes.annuler
rapports.voir         rapports.voir_consolide
parametres.gerer
membres.voir          membres.gerer         (inviter/révoquer des membres, assigner des rôles)
abonnement.gerer      (réservé au propriétaire du Business, pas scopé par store)
```

Chaque module ajoute ses propres permissions dans son `ServiceProvider` (ou un seeder dédié) au moment de son implémentation — pas de table de permissions figée à l'avance pour des modules non encore construits.

## 5. Vérification en couches (rappel)

1. **Middleware de route** : `->middleware('permission:produits.voir')` (middleware fourni par spatie, alias à activer dans `bootstrap/app.php` au moment de l'implémentation — déjà prévu en commentaire).
2. **Policy** : pour l'autorisation fine sur une instance précise (ex: `ventes.rembourser` ne suffit pas si la vente n'appartient pas à ce store — voir [multi-tenancy.md](multi-tenancy.md) Couche 3).
3. **FeatureGate** (module `Features`) : une permission n'a de sens que si la fonctionnalité est activée pour ce store — les deux vérifications sont indépendantes et toutes deux nécessaires (voir [features.md](features.md)).

## 5 bis. Pièges opérationnels du mode `teams` (audit)

Le mode teams de spatie repose sur un état global mutable (`PermissionRegistrar::setPermissionsTeamId()`, relayé ici par `StoreTeamResolver`/`TenantContext`). Ce mécanisme est solide **à condition de respecter trois règles** :

1. **Aucun appel à `$user->can()`, `$user->assignRole()`, `hasRole()`, etc. avant que `TenantContext::setStoreId()` ait été appelé.** En dehors du cycle HTTP normal (middleware déjà en place), tout code qui manipule des rôles — commande artisan, seeder, job — doit appeler explicitement `TenantContext::setStoreId($id)` en premier, sinon l'assignation part sur un `boutique_id` null ou celui laissé par une exécution précédente. À documenter comme prérequis obligatoire dans chaque futur seeder de rôles.
2. **La création d'une boutique et l'attribution de son rôle "Propriétaire" au(x) `BusinessUser`(s) se font dans la même transaction** que la création du `Store` : `TenantContext::setStoreId($nouveauStore->id)` doit être positionné manuellement juste après l'insertion du `Store` (le middleware de résolution ne s'exécute que sur des routes portant déjà un `{store}` existant dans l'URL, ce qui n'est pas le cas d'un `POST /boutiques` de création).
3. **Ce mode teams est confirmé comme la bonne solution** face à l'alternative "ACL maison" : aucun des points ci-dessus n'est un défaut du choix technique, ce sont des règles d'usage à documenter et tester une fois, pas des limitations qui remettent en cause la décision.

Voir aussi [multi-tenancy.md](multi-tenancy.md) §8 pour le risque spécifique aux workers long-lived (Octane).

> **Mise à jour (implémentation Phase 1, 2026-09-07)** : un point 4 s'ajoute à cette liste, trouvé en implémentant `StoreRoleProvisioner` — `Spatie\Permission\PermissionRegistrar` instancie la classe `team_resolver` avec un `new $class` **sans passer par le conteneur** (`PermissionRegistrar.php:60`), donc **`StoreTeamResolver` ne peut avoir aucune dépendance de constructeur** : `TenantContextContract` y est résolu via `app()` à l'intérieur de chaque méthode, jamais injecté. La version conçue en amont (avec injection de constructeur) aurait fait planter l'application au premier accès à une permission — corrigé avant d'aller plus loin, voir `docs/stores.md` "Bug trouvé et corrigé".

## 6. `platform_admin` — administration de la plateforme elle-même

Distinct du système ci-dessus : un administrateur MaBoutique (équipe support/produit) qui active/désactive des `BusinessDomain` ou des `Feature` globalement, ou consulte une boutique en support, n'est **pas** un `StoreUser` de cette boutique. Recommandation : un flag `is_platform_admin` sur `User` (ou un guard Sanctum distinct `platform`), vérifié par un middleware séparé (`EnsurePlatformAdmin`), avec un jeu de permissions qui lui est propre et un accès en lecture aux données de n'importe quel store **uniquement** via des endpoints dédiés `/api/admin/*`, jamais en empruntant les routes `/api/boutiques/{store}/*` normales (pour ne jamais mélanger les deux mécanismes d'autorisation).

## 7. Autorisation business-level, distincte du store-level (ajouté suite à l'audit)

Tout ce qui précède répond à la question "que peut faire cet utilisateur **dans cette boutique**". Certaines actions n'ont de sens qu'**au niveau du Business**, avant même qu'une boutique cible existe : créer une nouvelle boutique, gérer l'abonnement/la facturation, consulter un rapport consolidé multi-boutiques. spatie/laravel-permission en mode teams ne peut pas les exprimer nativement (un "team" est toujours un `boutique_id` existant).

**Décision** : ces quelques actions (volontairement peu nombreuses) sont vérifiées via la table `BusinessUser` (voir [database.md](database.md) §2), avec un simple champ `role` (`proprietaire`|`administrateur`) — **pas** une seconde instance de spatie, **pas** un système RBAC parallèle complet. Un pivot à deux valeurs suffit très largement à ce périmètre restreint et évite d'entretenir deux mécanismes d'autorisation différents pour un besoin aussi limité :

```php
// Exemple conceptuel, dans le service de création de boutique (module Tenancy)
abort_unless(
    $business->businessUsers()->where('user_id', $user->id)->exists(),
    403
);
```

Règle de nommage pour éviter toute confusion en revue de code : une permission spatie (store-level) s'écrit `module.action` (`produits.voir`) ; une action business-level se vérifie par un contrôle explicite sur `BusinessUser.role`, jamais via `$user->can()` (qui, sans `TenantContext` positionné sur un store, n'a de toute façon pas de sens).

## 8. Tests obligatoires

Voir [testing.md](testing.md) — toute nouvelle permission doit être accompagnée d'au moins un test qui vérifie qu'un utilisateur **sans** cette permission reçoit un 403, et un test qui vérifie qu'un rôle/permission accordé sur le Store A ne s'applique pas sur le Store B pour le même utilisateur.

> **Mise à jour (2026-10-13) — `platform_admin` implémenté.** Colonne `utilisateurs.est_admin_plateforme` (jamais modifiable par l'API ; accordée par `php artisan admin:creer {email}`). Interface d'administration en **Blade** (module `Admin`, session web, guard `web`) : connexion à la racine du site (`/`), pages sous `/admin` protégées par le middleware `admin.plateforme` (`EnsurePlatformAdmin`). Elle n'emprunte jamais les routes `/api/boutiques/{store}/*`. Fonctions : tableau de bord, entreprises (suspension, abonnement géré à la main), boutiques (désactivation), utilisateurs (désactivation = jetons Sanctum révoqués), forfaits (prix, quotas, fonctionnalités), domaines d'activité (fonctionnalités par défaut). Une entreprise suspendue ou une boutique désactivée renvoie 403 `BOUTIQUE_SUSPENDUE` sur toutes les routes de boutique (`ResolveStoreContext`). Fichiers statiques dans `public/admin-assets` (un dossier `public/admin` masquerait les routes `/admin`), police d'icônes Ionicons identique à l'application mobile.
