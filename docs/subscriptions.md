# Abonnements SaaS

> **Mise à jour (implémentation Phase 2, 2026-09-07)** : la version minimale décrite ci-dessous est implémentée — `Plan`, `Subscription`, `fonctionnalites_forfait` (pivot pur, sans modèle dédié), `SubscriptionLimits::assertCanCreateStore()`. Voir [feature-gate.md](feature-gate.md) §4-5 pour la décision "pas d'abonnement = pas de restriction" (fail-open), nécessaire pour ne pas casser les Business créés en Phase 1 sans Subscription.

## 1. Portée de cette phase

Le **paiement** (Stripe, mobile money, etc.) est explicitement hors périmètre ici. Ce document définit uniquement la relation `Plan → Subscription → Business → Limites/Features`, pour que le module `Subscriptions` puisse être implémenté plus tard sans remettre en cause le reste du système (en particulier [features.md](features.md), qui dépend déjà de cette relation).

## 2. Modèle de données

```
Plan ──< PlanFeature >── Feature
 │
 └──< Subscription >── Business
           │
           └── limites : max_stores, max_users, max_products, ...
```

### Plan
- Attributs : `id`, `code` (unique, ex: `free`, `basic`, `pro`, `business`), `nom`, `prix_mensuel`, `prix_annuel` (nullable), `max_boutiques`, `max_utilisateurs_par_boutique`, `max_produits_par_boutique` (nullable = illimité), `actif`.
- Relations : `belongsToMany(Feature, via: PlanFeature)`.

### PlanFeature (pivot)
- Attributs : `forfait_id`, `fonctionnalite_id`. La simple présence de la ligne signifie "ce plan inclut cette feature". Absence de ligne pour un plan = pas de restriction supplémentaire par feature pour ce plan (voir algorithme `FeatureGate` en §4 de [features.md](features.md) : "si le plan n'a AUCUNE `PlanFeature`, on ne restreint pas par plan" — utile pour un plan `FREE` volontairement laissé "toutes features de base" et restreint uniquement par les compteurs `max_*`).

### Subscription
- Attributs : `id`, `entreprise_id` (unique — un `Business` a un seul abonnement actif à la fois), `forfait_id`, `statut` (`trialing`, `actif`, `past_due`, `annulee`), `fin_essai_le` (nullable), `debut_periode_le`, `fin_periode_le`, `annule_le` (nullable).
- Relations : `belongsTo(Business)`, `belongsTo(Plan)`.
- Règles métier : le changement de plan (upgrade/downgrade) crée une nouvelle ligne d'historique plutôt que d'écraser silencieusement l'ancienne (à trancher à l'implémentation : soit `Subscription` versionnée avec `ends_at`, soit une table `SubscriptionHistory` séparée — décision différée, sans impact sur le reste de l'architecture).

## 3. Limites (quotas), distinctes des features

Une **feature** est un "oui/non" (le store a-t-il accès à ce module). Une **limite** est un compteur (combien de boutiques, d'utilisateurs, de produits). Les deux sont vérifiées à des moments différents :

- Feature → vérifiée à chaque requête entrante (middleware, voir [features.md](features.md)).
- Limite → vérifiée au moment de la **création** d'une ressource comptée (créer une boutique, inviter un utilisateur, créer un produit), jamais en lecture.

```php
// Exemple conceptuel, dans le service de création de boutique (module Tenancy)
if ($business->stores()->count() >= $business->subscription->plan->max_stores) {
    throw new SubscriptionLimitExceededException('max_stores');
}
```

Recommandation : centraliser ces vérifications dans un service `SubscriptionLimits` (module `Subscriptions`) plutôt que de disperser des `count() >= max_*` dans chaque module — chaque module appelle `SubscriptionLimits::canCreate($business, 'boutiques')` sans connaître le détail du plan.

## 4. Exemple de grille (illustratif, à valider avec le métier)

| Plan | Boutiques | Utilisateurs/boutique | Produits/boutique | Rapports avancés |
|---|---|---|---|---|
| FREE | 1 | 2 | 50 | Non |
| BASIC | 1 | 5 | 500 | Non |
| PRO | 3 | 15 | Illimité | Oui |
| BUSINESS | Illimité | Illimité | Illimité | Oui |

Cette grille est une donnée (`Plan` + `PlanFeature` + colonnes `max_*`), jamais codée en dur — modifiable par l'admin plateforme sans déploiement, exactement comme le mapping `DomainFeature`.

## 5. Ce qui reste hors périmètre

Paiement récurrent, période d'essai avec carte bancaire, webhooks de facturation, gestion des factures/reçus d'abonnement. Le module `Subscriptions` tel que conçu ici peut fonctionner en mode "abonnement géré manuellement par l'admin plateforme" (changer le `forfait_id` d'un `Business` à la main) en attendant l'intégration d'un fournisseur de paiement — c'est délibéré pour ne pas bloquer le développement du reste du produit sur une décision de fournisseur de paiement pas encore prise.
