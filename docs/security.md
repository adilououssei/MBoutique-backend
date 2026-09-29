# Stratégie de sécurité

La sécurité multi-tenant est traitée en détail dans [multi-tenancy.md](multi-tenancy.md) et considérée comme la priorité absolue. Ce document couvre le reste du périmètre sécurité.

## 1. Authentification

- Laravel Sanctum, tokens personnels avec abilities/scopes si un besoin de token à privilèges restreints apparaît (ex: token mobile "lecture seule" pour une future intégration).
- Expiration de token configurable (`config/sanctum.php`), révocation possible côté serveur (déconnexion à distance).
- Politique de mot de passe : règles Laravel par défaut (`Password::min(8)->letters()->numbers()`), à durcir selon exigences finales.
- Rate limiting spécifique sur `/auth/connexion` (voir §5) pour limiter le brute force, indépendamment du rate limiting global de l'API.

## 2. Autorisation

Empilement documenté dans [permissions.md](permissions.md) : middleware `permission:*` + Policies systématiques sur toute ressource identifiée par ID. Règle d'équipe : **aucune route retournant une ressource unique par ID ne doit exister sans Policy associée**, vérifiable en revue de code / test automatisé (voir [testing.md](testing.md)).

## 3. Protection contre l'IDOR (Insecure Direct Object Reference)

Le risque IDOR ici est spécifiquement un risque **cross-tenant** (accéder à la ressource d'un autre store via son ID). Couvert par la Couche 3 de [multi-tenancy.md](multi-tenancy.md). Règle supplémentaire : ne jamais exposer d'ID auto-incrémenté d'une table sensible dans une URL publique sans vérification de policy immédiate — le binding de route Laravel résout l'objet, la policy doit s'exécuter avant tout accès à ses attributs.

## 4. Mass assignment

Chaque `Model` définit `$fillable` explicitement (jamais `$guarded = []`). Les champs sensibles (`boutique_id`, `entreprise_id`, `proprietaire_id`, tout champ de rôle/permission) ne sont **jamais** dans `$fillable` d'un modèle exposé à une création via `FormRequest` — ils sont renseignés côté serveur (`boutique_id` via `BelongsToStore::creating`, déjà implémenté) ou via une relation explicite, jamais depuis l'input brut de la requête.

**Convention actée en Phase 1** pour le cas où un *service* (code de confiance, pas une requête utilisateur) doit renseigner un champ volontairement non fillable (ex: `Business.proprietaire_id`, `Store.entreprise_id`) : construire le modèle avec les données validées, puis fixer le champ serveur via `forceFill()` juste avant `save()` — jamais élargir `$fillable` pour accommoder ce besoin, ce qui rouvrirait le champ à un futur endpoint qui accepterait de l'input utilisateur sans y repenser. Voir `BusinessService::createForOwner` / `StoreService::createForBusiness`.

## 5. Rate limiting

- Global : throttle Laravel par défaut sur le groupe `api` (`config/sanctum.php`/`RouteServiceProvider` équivalent dans `bootstrap/app.php`), ex: 60 req/min par utilisateur authentifié.
- Spécifique login : throttle plus strict par IP + par identifiant tenté (ex: 5 tentatives/minute) pour limiter le brute force sans bloquer un usage légitime multi-appareil.
- Spécifique actions sensibles (remboursement, suppression) : envisager un throttle dédié si abus constaté, pas nécessaire dès le départ.

## 6. CORS et CSRF

- API consommée par une app mobile (pas de navigateur, pas de CORS/CSRF pertinent) et potentiellement un futur client web séparé (React sur un autre domaine) : CORS configuré explicitement par domaine autorisé (`config/cors.php`), jamais `*` en production.
- CSRF : non pertinent pour des requêtes Bearer token pures (mobile) ; si un client web same-origin utilise le mode Sanctum "stateful" (cookies), le middleware CSRF standard de Laravel s'applique alors sur ce chemin uniquement.

## 7. Fichiers uploadés

- Validation stricte du type MIME réel (pas seulement l'extension) et de la taille.
- Stockage hors du webroot public direct pour tout fichier qui doit être contrôlé par policy (voir [multi-tenancy.md](multi-tenancy.md) point "upload de fichier"), servi via une route applicative qui vérifie l'appartenance au store.
- Nom de fichier généré côté serveur (UUID), jamais le nom d'origine utilisé tel quel (path traversal, collision).

## 8. Logs et données sensibles

- Aucune donnée sensible (mot de passe, token, moyen de paiement) dans les logs applicatifs.
- Logs d'audit dédiés pour les actions sensibles (remboursement, suppression d'utilisateur, changement de permission, désactivation de compte) — table ou canal de log séparé du log applicatif général, avec `utilisateur_id`, `boutique_id`, action, horodatage.
- Erreurs 500 : message générique côté client, détail complet uniquement dans les logs serveur (`APP_DEBUG=false` en production, non négociable).

## 9. Injection SQL

Usage exclusif du Query Builder / Eloquent avec bindings paramétrés. Toute requête brute (`DB::raw`, `whereRaw`) passe en revue de code obligatoire et n'accepte jamais une valeur utilisateur non bindée.

## 10. XSS

Réponses API en JSON (pas de rendu HTML côté serveur pour du contenu utilisateur) : le risque XSS classique se déplace vers le frontend (React échappe par défaut). Point de vigilance backend : tout contenu utilisateur stocké (nom de produit, notes) doit rester tel quel en base (pas d'échappement à l'écriture, qui casserait l'affichage ailleurs) — l'échappement est une responsabilité du renderer, jamais du stockage.

## 11. Secrets et configuration

`.env` jamais commité (déjà dans `.gitignore` par le squelette Laravel), toutes les clés sensibles (DB, Sanctum, futur fournisseur de paiement/notification) exclusivement en variables d'environnement, jamais en dur dans le code ou en config committée.

## 12. Constats de l'audit du 2026-09-06 (classés par sévérité)

Voir [audit-2026-09.md](audit-2026-09.md) pour le déroulé complet de l'audit. Ce tableau est la synthèse actionnable.

| Sévérité | Constat | État |
|---|---|---|
| **CRITIQUE** | `BelongsToStore::creating` ne faisait que *combler* un `boutique_id` vide, sans écraser une valeur mass-assignée par le client — un `boutique_id` forgé dans le payload aurait pu attacher une ressource à un autre tenant | ✅ Corrigé pendant l'audit (`app/Shared/Tenancy/Concerns/BelongsToStore.php`), `boutique_id` est désormais toujours forcé depuis `TenantContext` et immuable après création |
| **CRITIQUE** | Absence de règle de validation scopée sur les champs `*_id` référençant une ressource tenant-scopée dans le corps d'une requête (`client_id`, `produit_id`, ...) — injection de relation inter-tenant, indétectable par le scope global ou une Policy classique | ⏳ Règle documentée ([multi-tenancy.md](multi-tenancy.md) Couche 5), à appliquer dès le premier `FormRequest` écrit ; aucun code à corriger aujourd'hui (aucun `FormRequest` n'existe encore) |
| **ÉLEVÉ** | Aucun usage des *scoped route bindings* Laravel sur les futures routes imbriquées `stores.{ressource}` — la Policy devient le seul filet de sécurité contre un ID substitué | ⏳ Convention documentée ([multi-tenancy.md](multi-tenancy.md) Couche 6), à appliquer dès la première route de ce type |
| **ÉLEVÉ** | Les transferts de stock inter-boutiques (`transfer_in`/`transfer_out`) sont une écriture cross-tenant légitime mais n'avaient aucune règle d'autorisation formalisée — un tel mécanisme mal gardé serait indistinguable d'une vulnérabilité | ✅ Formalisé ([multi-tenancy.md](multi-tenancy.md) §7) : mêmes `entreprise_id`, permission dédiée, appartenance aux deux stores, journalisation |
| **MOYEN** | Race condition possible sur le stock lors de ventes concurrentes sur le même produit (lecture-puis-écriture non verrouillée) | ✅ Parade documentée ([database.md](database.md) §11) : `lockForUpdate()` dans la transaction d'écriture du `StockMovement` |
| **MOYEN** | `register_octane_reset_listener` (spatie) à `false` : non exploitable tant qu'Octane n'est pas utilisé, mais deviendrait un vecteur de fuite inter-tenant silencieuse (état d'un `TenantContext` d'une requête qui fuite vers la suivante sur un worker persistant) si Octane était activé sans reprendre cette checklist | ⏳ Documenté comme prérequis bloquant avant toute activation d'Octane ([multi-tenancy.md](multi-tenancy.md) §8) |
| **MOYEN** | Pas de mécanisme d'idempotence sur la création d'une vente — un double-tap/retry réseau côté mobile peut créer deux ventes et double-décrémenter le stock | ✅ `Sale.cle_idempotence` ajouté au schéma ([database.md](database.md) §7) |
| **FAIBLE** | Le rôle `platform_admin` était mentionné mais sans middleware/guard concret défini | ⏳ Reste à concevoir en détail à l'implémentation du module d'administration plateforme (Phase 6 de [roadmap.md](roadmap.md)) — pas bloquant pour les phases 1 à 5 |
| **FAIBLE** | Types de colonnes monétaires non explicités dans `database.md` (risque d'implémentation en `FLOAT`) | ✅ Corrigé ([database.md](database.md) §0) : `DECIMAL(12,2)` obligatoire, jamais `FLOAT`/`DOUBLE` |
| **FAIBLE** | Aucune modélisation des dépendances entre features (ex: `rendez_vous` activable sans `services`) | ✅ `FeatureDependency` ajouté ([features.md](features.md) §7) |

## 12 bis. Constats supplémentaires — implémentation Phase 1 (2026-09-07)

| Sévérité | Constat | État |
|---|---|---|
| **CRITIQUE** | `StoreTeamResolver` (config `permission.team_resolver`) avait une dépendance de constructeur ; `Spatie\Permission\PermissionRegistrar` l'instancie par `new $class` sans conteneur, ce qui aurait fait planter l'application au premier accès à une permission | ✅ Corrigé : résolution de `TenantContextContract` via `app()` à l'intérieur des méthodes, plus de constructeur. Voir `docs/stores.md` |
| **ÉLEVÉ** | `Business::create()`/`Store::create()` échouaient (contrainte NOT NULL) car `proprietaire_id`/`entreprise_id` sont volontairement absents de `$fillable`, silencieusement ignorés par le mass assignment même depuis un service de confiance | ✅ Corrigé via `forceFill()` (voir §4 ci-dessus) |
| **MOYEN** | `UserResource`/`BusinessResource`/`StoreResource` plantaient (500) sur un modèle fraîchement créé : Eloquent ne relit pas les colonnes à défaut SQL après `create()` | ✅ Corrigé par des défauts au niveau modèle (`protected $attributes`) sur `User`, `Business`, `Store` |
| **FAIBLE** | Le cache du guard Sanctum au sein d'un même test PHPUnit peut masquer une révocation de token entre deux appels HTTP simulés dans la même méthode de test (artefact de test, pas un bug applicatif) | ✅ Documenté et contourné dans `LogoutTest` via `Auth::forgetGuards()` entre les deux appels |

## 12 ter. Constats supplémentaires — implémentation Phase 2 (2026-09-07)

| Sévérité | Constat | État |
|---|---|---|
| **FAIBLE** | `BusinessDomain.actif`/`Feature.actif` étaient absents de `$fillable` par réflexe (même vigilance que pour les FK serveur), alors que ce sont de simples champs d'administration destinés à être modifiés — `update(['actif' => false])` était silencieusement ignoré | ✅ Corrigé : `actif` ajouté au `$fillable` des deux modèles ; rappel que la règle "pas fillable" ne s'applique qu'aux champs d'identité/tenant (`boutique_id`, `entreprise_id`, `proprietaire_id`), pas à tout champ sensible en général |
| Confirmation | `StoreFeatureOverride` (nouveau modèle tenant-scopé) respecte `BelongsToStore` dès sa conception — testé explicitement (`StoreFeatureOverrideIsolationTest`) plutôt que découvert après coup | ✅ Aucune régression du type Phase 1 |

## 13. Résumé des priorités (renuméroté suite à l'audit)

1. Isolation multi-tenant (voir document dédié) — priorité absolue.
2. Autorisation systématique par Policy + permission scopée.
3. Mass assignment / validation stricte.
4. Rate limiting sur l'authentification.
5. Le reste (CORS, uploads, logs) suit les bonnes pratiques Laravel standards, sans besoin d'innovation particulière pour ce produit.
