# FeatureGate (Phase 2)

Module `app/Modules/Features/`. Ce document est la référence d'implémentation ; `docs/features.md` reste la référence de conception d'origine (les deux se recoupent volontairement, celui-ci précise ce qui a été concrètement construit et les ambiguïtés tranchées).

## 1. Modèle de données implémenté

```
BusinessDomain ──< DomainFeature >── Feature ──< FeatureDependency >── Feature (self)
       │                                  │
     Store ──< StoreFeatureOverride ──────┤
       │                                  │
   Business ──< Subscription >── Plan ──< plan_features >──┘
```

| Table | Modèle | Scope | Rôle |
|---|---|---|---|
| `domaines_activite` | `BusinessDomain` | global | Le métier (coiffure, alimentation, ...) |
| `fonctionnalites` | `Feature` | global | Une capacité (products, sales, ...) |
| `fonctionnalites_domaine` | `DomainFeature` | global | Défaut d'un domaine pour une feature (`active_par_defaut`) |
| `dependances_fonctionnalites` | `FeatureDependency` | global | `fonctionnalite_id` exige `depend_de_fonctionnalite_id` |
| `fonctionnalites_boutique` | `StoreFeatureOverride` | **tenant** (`BelongsToStore`) | Dérogation d'une boutique précise |
| `forfaits` | `Plan` | global | Offre d'abonnement, porte aussi les **limites** (`max_boutiques`, ...) |
| `fonctionnalites_forfait` | *(pivot, pas de modèle)* | global | Un plan liste-t-il cette feature |
| `abonnements` | `Subscription` | 1:1 par `Business` | L'abonnement actif d'un Business |

**Écart assumé par rapport à `database.md`** : `DomainFeature` et `FeatureDependency` utilisent une clé primaire auto-incrémentée + contrainte `unique` plutôt qu'une clé composite littérale — le support des clés composites d'Eloquent est trop limité pour que la fidélité au schéma conceptuel vaille la complexité de code que ça imposerait. La garantie d'unicité est strictement identique.

## 2. Ordre de résolution (`FeatureGate::allows()` / `resolveForStore()`)

Le point 7 du besoin de cette phase listait 7 vérifications possibles ; certaines ne sont **pas** des étapes d'exécution mais des moments différents du cycle de vie. Voici l'ordre réellement implémenté, tranché explicitement :

1. **La Feature existe et est active globalement** (`Feature.actif`). Sinon → `false`, immédiatement. C'est le coupe-circuit plateforme.
2. **Le plan d'abonnement autorise la feature.** Si le `Business` a une `Subscription` dont le `Plan` liste au moins une feature, celle demandée doit y figurer. Un plan qui ne liste **aucune** feature n'impose aucune restriction (cas `FREE`, voir `docs/subscriptions.md`). Un `Business` **sans** `Subscription` du tout n'est pas non plus restreint (voir §4 "Pourquoi fail-open").
3. **Le mot de la boutique** : un `StoreFeatureOverride` existant l'emporte toujours ; à défaut, le défaut du domaine (`DomainFeature.active_par_defaut`) ; à défaut, `false`.
4. **Les dépendances** (`FeatureDependency`) : la feature ne peut être vraie que si toutes ses dépendances le sont aussi (résolution récursive, avec protection anti-cycle).

**`BusinessDomain.actif` est délibérément absent de cette liste d'exécution.** Ce champ ne gate que la **sélection** d'un domaine à la création (ou au changement) de boutique — `CreateStoreRequest` rejette un `domaine_activite_id` inactif. Une fois la boutique créée, désactiver son domaine plus tard (l'admin plateforme retire un métier du catalogue) **ne casse jamais** les boutiques déjà existantes : elles continuent de résoudre leurs features normalement. C'était la principale ambiguïté du besoin ; l'alternative (propager la désactivation en temps réel) ferait d'une action administrative rare et anodine une panne de production silencieuse pour tous les clients existants de ce domaine — jugé inacceptable.

## 3. Feature ≠ Permission

Ce sont deux questions indépendantes, jamais fusionnées :

| | Question | Répond | Portée |
|---|---|---|---|
| `FeatureGate::allows($store, 'ventes')` | Cette capacité existe-t-elle pour **cette boutique** ? | Indépendamment de qui est connecté | Store |
| `$user->can('ventes.creer')` | **Cet utilisateur** a-t-il le droit d'agir ? | Indépendamment de si la feature existe | User × Store (team spatie) |

Chaîne de middleware sur une future route métier : `auth:sanctum → store → feature:sales → permission:ventes.creer`. Si `feature:ventes` échoue, l'erreur est `403 FONCTIONNALITE_DESACTIVEE` — jamais confondue avec un `403` d'autorisation classique (code différent). Prouvé par test (`FeatureVersusPermissionTest`) : une feature désactivée bloque même le propriétaire de la boutique (qui a toutes les permissions) ; une feature activée bloque quand même un utilisateur sans la permission.

## 4. Pourquoi "fail-open" en l'absence d'abonnement

`Business::createForOwner` (Phase 1) ne crée **aucune** `Subscription`. Si `FeatureGate` traitait "pas d'abonnement" comme "aucune feature autorisée", **toutes** les boutiques créées avant l'introduction des abonnements perdraient l'accès à tout, silencieusement, dès le déploiement de cette phase — une régression majeure explicitement interdite par la consigne. Décision : pas d'abonnement = pas de restriction par le plan (seul le mot du domaine/de la boutique compte). C'est cohérent avec `docs/subscriptions.md` §5 ("géré manuellement par l'admin plateforme" en attendant un vrai fournisseur de paiement).

## 5. Limites (`SubscriptionLimits`), distinctes des Features

`App\Modules\Subscriptions\Services\SubscriptionLimits` répond à une question totalement différente : "combien de X ce Business a-t-il le droit de créer", vérifié **uniquement à la création** d'une ressource comptée — jamais à chaque requête comme `FeatureGate`. Implémenté cette phase : `assertCanCreateStore()` (utilise `Plan.max_boutiques`, `null` = illimité), branché dans `StoreService::createForBusiness`. `max_utilisateurs_par_boutique` et `max_produits_par_boutique` existent déjà au schéma mais n'ont pas encore de point d'application (rien à limiter : pas de module Employees/Products cette phase) — à brancher quand ces modules arriveront.

## 6. Administration des domaines et features

**Aucune API d'administration n'a été construite cette phase**, conformément à `docs/roadmap.md` qui la place en Phase 6 (`platform_admin`). Domaines et features sont actuellement gérés par seeder (`database/seeders/FeatureSeeder.php`), idempotent (`firstOrCreate`), éditable en relançant le seeder avec des données modifiées. La seule API HTTP de cette phase est **en lecture** : `GET /boutiques/{store}/fonctionnalites`.

## 7. `GET /api/boutiques/{store}/fonctionnalites`

Chaîne : `auth:sanctum → store (Couche 1) → StorePolicy::view (n'importe quel membre actif)`. Aucune permission spécifique requise — c'est une information de configuration que tout membre doit pouvoir lire pour que l'application mobile construise son menu.

```json
{
  "succes": true,
  "donnees": {
    "domaine": { "slug": "coiffure", "nom": "Coiffure" },
    "fonctionnalites": [
      { "slug": "services", "activee": true },
      { "slug": "rendez_vous", "activee": true },
      { "slug": "stock", "activee": false }
    ]
  },
  "message": null,
  "meta": {}
}
```

Toutes les features globalement actives sont listées (activées ou non) — pas seulement celles activées — pour que le frontend puisse, par exemple, afficher grisée une fonctionnalité indisponible plutôt que de la cacher silencieusement.

## 8. Cache — décision : aucun pour l'instant

Analysé, explicitement refusé à ce stade : les tables concernées (`fonctionnalites`, `domaines_activite`, `fonctionnalites_domaine`, `dependances_fonctionnalites`) totalisent quelques dizaines de lignes, interrogées par des requêtes indexées triviales. Mettre en cache maintenant ajouterait un risque d'invalidation (donnée de feature obsolète après un changement d'override ou de plan — exactement le genre de bug de fraîcheur que la consigne demande d'éviter) pour un gain de performance nul à ce volume. **Déclencheur de réévaluation** : si `FeatureGate::resolveForStore()` apparaît comme un point chaud mesurable une fois Sales/Products en charge réelle, envisager un cache par store invalidé sur écriture de `StoreFeatureOverride`/changement de plan — pas avant.

## 9. Exemples vérifiés (tinker + tests automatisés)

| Domaine | Feature testée | Résultat |
|---|---|---|
| `coiffure` | `services`, `rendez_vous` | `true` |
| `coiffure` | `stock` | `false` |
| `alimentation_generale` | `produits`, `stock` | `true` |
| `alimentation_generale` | `rendez_vous` | `false` |
| `restaurant` + override `inventory=false` | `stock` | `false` (l'override l'emporte sur le défaut du domaine) |
| `restaurant`, plan limité à `commandes` | `tables` (dépend de `commandes`, `commandes` lui-même autorisé) | `false` (le plan bloque `tables` directement, indépendamment de sa dépendance) |
