# Domaines métiers et système de features

> **Mise à jour (audit architectural du 2026-09-06)** : voir [audit-2026-09.md](audit-2026-09.md). Ajout du §7 "Dépendances entre features", qui comblait un vide (rien n'empêchait d'activer `appointments` sans `services`/`employees`).
>
> **Mise à jour (implémentation Phase 2, 2026-09-07)** : ce document reste la référence de conception ; voir **[feature-gate.md](feature-gate.md)** pour l'implémentation réelle, l'ordre de résolution définitivement tranché (en particulier le rôle de `BusinessDomain.is_active`, laissé ambigu ici), et les exemples vérifiés par test. `code`/`label` du schéma d'origine ont été implémentés comme `slug`/`name` pour rester cohérents avec la convention déjà en place sur `Store` (Phase 1). `categories` est bien une `Feature` à part entière, pas un synonyme inclus dans `products` comme suggéré au §3 ci-dessous.

## 1. Objectif

Éviter absolument le pattern `if ($store->type === 'restaurant') { ... }` dispersé dans le code. À la place, chaque module métier expose sa disponibilité sous la forme d'une **feature nommée** (`products`, `appointments`, `cash_register`, ...), et le système répond à une seule question, toujours de la même façon : *"la feature X est-elle active pour ce store, maintenant ?"*.

## 2. Modèle de données (voir aussi [database.md](database.md) §4)

```
BusinessDomain ──< DomainFeature >── Feature
       │                                  ▲
       │                                  │
     Store ──< StoreFeatureOverride ──────┘
       │
   Subscription (Plan) ──< PlanFeature >── Feature
```

- `BusinessDomain` : le métier choisi à la création de la boutique (alimentation générale, coiffeur, ...), gérable par l'admin plateforme.
- `Feature` : une capacité activable, correspondant en général à un module (`products`, `services`, `inventory`, `sales`, `cash_register`, `customers`, `suppliers`, `employees`, `appointments`, `orders`, `reports`).
- `DomainFeature` : mapping par défaut — quelles features sont actives pour un domaine donné.
- `StoreFeatureOverride` : une boutique précise peut déroger au défaut de son domaine (ex: une "alimentation générale" qui veut aussi activer `appointments` pour des commandes sur réservation).
- `PlanFeature` (voir [subscriptions.md](subscriptions.md)) : le plan d'abonnement peut lui aussi restreindre l'accès à une feature indépendamment du domaine (ex: `reports.advanced` réservé aux plans PRO/BUSINESS).

## 3. Exemples de mapping par défaut

| Domaine | Features par défaut |
|---|---|
| `alimentation_generale` | products, categories(inclus dans products), inventory, sales, cash_register, customers, suppliers, employees, reports |
| `coiffure` | services, customers, appointments, employees, cash_register, reports |
| `restaurant` | products (menu), orders, tables, sales, cash_register, inventory, customers, employees, reports |
| `pharmacie` | products, inventory, sales, cash_register, customers, suppliers, employees, reports |
| `autre` | products, services, sales, cash_register, customers, reports (jeu minimal générique) |

Ce mapping vit en base (`DomainFeature`), pas dans le code — modifiable par l'admin plateforme sans déploiement.

## 4. Algorithme de résolution (service `FeatureGate`, module `Features`)

```
est_active(store, feature_code):
    feature = Feature.where(code=feature_code).first()
    si feature est null ou feature.is_active == false:
        retourner false                                    # coupure globale plateforme

    plan = store.business.subscription.plan
    si plan a des PlanFeature ET feature_code n'y est pas listé:
        retourner false                                     # non couvert par l'abonnement

    override = StoreFeatureOverride.where(store, feature).first()
    si override existe:
        retourner override.is_enabled                       # la boutique a explicitement choisi

    domainFeature = DomainFeature.where(store.business_domain, feature).first()
    retourner domainFeature?.is_default_enabled ?? false     # défaut du métier, sinon désactivé par prudence
```

Ordre de priorité (du plus fort au plus faible) : **coupure plateforme > limite d'abonnement > choix explicite de la boutique > défaut du domaine**. Une boutique ne peut jamais s'auto-activer une feature que son abonnement n'inclut pas — c'est ce qui rend `Subscriptions` réellement contraignant plutôt que déclaratif.

## 5. Utilisation dans le code (au moment de l'implémentation)

Middleware de route, alias `feature` (déjà prévu en commentaire dans `bootstrap/app.php`) :

```php
Route::middleware(['auth:sanctum', 'store', 'feature:appointments'])
    ->group(base_path('routes/api/appointments.php'));
```

Le middleware appelle `FeatureGate::check($store, 'appointments')` et retourne 403 (`FEATURE_DISABLED`) si absent — **avant** toute vérification de permission, car l'absence de la feature n'est pas une question de "qui a le droit" mais de "cette fonctionnalité n'existe pas pour cette boutique".

Le frontend (React/mobile) doit pouvoir construire dynamiquement son menu/navigation à partir d'un endpoint `GET /api/stores/{store}/features` qui retourne la liste résolue des features actives pour ce store — évitant de dupliquer l'algorithme ci-dessus côté client.

## 6. Ajouter un nouveau métier sans toucher au code

1. L'admin plateforme crée un `BusinessDomain` (`code`, `label`).
2. Il associe les `Feature` existantes pertinentes via `DomainFeature`.
3. Si le métier a besoin d'une capacité qui n'existe dans aucune `Feature` actuelle, c'est un signal qu'un **nouveau module** doit être développé (la feature reflète toujours un module réel, jamais une feature "vide" sans code derrière).

Ce mécanisme garantit que l'ajout d'un métier (ex: "pressing") est une opération de configuration (quelques lignes en base), pas un déploiement de code, **tant que** les modules nécessaires existent déjà.

## 7. Dépendances entre features (ajouté suite à l'audit)

**Problème identifié** : rien n'empêche aujourd'hui `StoreFeatureOverride` d'activer `appointments` sur une boutique sans que `services` et `employees` soient également actives — or un rendez-vous référence obligatoirement un `service_id`. Une feature activée seule, sans les capacités dont elle dépend fonctionnellement, casse silencieusement l'expérience plutôt que de le signaler.

**Décision** : ajouter une table `FeatureDependency` (`feature_id`, `depends_on_feature_id`), données pures (ex: `appointments` dépend de `services` et de `employees`). La résolution `FeatureGate` (§4) est complétée d'une règle : **une feature ne peut être effectivement active que si toutes ses dépendances le sont aussi** — sinon elle est traitée comme inactive et un avertissement est journalisé (signal qu'une configuration de `DomainFeature`/`StoreFeatureOverride` est incohérente, à corriger côté admin plateforme, pas une erreur silencieusement absorbée). Exemple concret pour le domaine `restaurant` du besoin initial : la feature `tables` (gestion de plan de salle, voir [database.md](database.md) §13) dépend de `orders` — un domaine `delivery`-only peut activer `orders` sans `tables`, mais pas l'inverse.

Cette table reste optionnelle à alimenter : une feature sans ligne dans `FeatureDependency` n'a simplement aucune dépendance, ce qui est le cas de la majorité des features de départ (`products`, `customers`, `reports`, ...).
