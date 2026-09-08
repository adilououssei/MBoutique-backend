# Modules

## Anatomie standard d'un module

Chaque dossier sous `app/Modules/<Nom>/` suivra, au moment de son implémentation, la même structure interne (aucun de ces sous-dossiers n'est pré-créé maintenant pour éviter de polluer le dépôt de dossiers vides pendant la phase de conception) :

```
app/Modules/<Nom>/
├── Http/
│   ├── Controllers/
│   ├── Requests/          # FormRequests (validation)
│   ├── Resources/         # transformation JSON (API Resources)
│   └── Middleware/        # middleware propre au module, s'il y en a
├── Models/
├── Policies/
├── Services/              # logique métier qui ne tient pas dans un controller/model
├── Providers/
│   └── <Nom>ServiceProvider.php   # enregistre routes, policies, observers du module
├── Database/
│   ├── Migrations/
│   └── Factories/
├── Routes/
│   └── api.php            # inclus par routes/api.php (voir architecture.md §5)
└── Tests/
    ├── Feature/
    └── Unit/
```

Le `ServiceProvider` de chaque module est enregistré dans `bootstrap/providers.php` au moment de l'implémentation du module (pas avant, pour ne pas booter un provider vide). Les migrations d'un module restent dans son propre dossier plutôt que dans `database/migrations/` global, pour que la suppression ou l'extraction future d'un module reste une opération de suppression de dossier, pas une chasse aux fichiers dispersés.

## Liste des modules et responsabilités

### Auth
Mécanique d'authentification uniquement : inscription, connexion/déconnexion, émission/révocation des tokens Sanctum, réinitialisation de mot de passe, vérification d'e-mail, endpoint `/me`. Ne possède pas le profil utilisateur métier (voir `Users`) — volontairement séparé pour que la logique "comment on prouve qui on est" reste indépendante de "qui est cet utilisateur".

### Users
Le `User` en tant qu'entité plateforme : nom, e-mail, téléphone, avatar, préférences (langue, notifications), statut (actif/désactivé). Un `User` existe indépendamment de toute boutique — c'est lui qui, via `Tenancy`, devient membre d'une ou plusieurs boutiques.

### Tenancy
Cœur du multi-tenant : `Business` (l'entreprise/organisation qui possède un abonnement), `Store` (une boutique physique/virtuelle rattachée à un Business), `StoreUser` (la relation d'appartenance + invitation). Fournit le middleware de résolution du tenant courant et alimente `TenantContext` (déjà implémenté dans `app/Shared/Tenancy/`). Voir [multi-tenancy.md](multi-tenancy.md).

### Authorization
Encapsule `spatie/laravel-permission` : gestion des `Role` et `Permission`, rôles prédéfinis (propriétaire, manager, caissier, employé) fournis comme des "rôles modèles" copiables, mais le système reste générique — une boutique peut créer ses propres rôles avec sa propre combinaison de permissions. Voir [permissions.md](permissions.md).

### Features
Le registre des `BusinessDomain` (alimentation générale, boucherie, coiffeur, ...) et des `Feature` (capacités activables : products, appointments, cash_register, ...), la table de mapping domaine→features par défaut, et les surcharges par boutique. Expose le service `FeatureGate` utilisé par le middleware `feature:<code>` pour bloquer l'accès à un module non activé pour une boutique donnée. Voir [features.md](features.md).

### Catalog
Ce qu'une boutique vend : `Product` (bien physique, avec stock) et `Service` (prestation, sans stock), tous deux implémentant le contrat `Sellable` consommé par `Sales`. Inclut `ProductCategory`/`ServiceCategory`. Voir [database.md](database.md) §10 pour la justification du choix "deux modèles + un contrat" plutôt qu'une table polymorphique unique.

### Inventory
Gestion du stock des `Product` uniquement (un `Service` n'a pas de stock). Ne modifie jamais une quantité directement : chaque changement s'enregistre comme un `StockMovement` immuable (achat, vente, perte, correction, transfert), le solde courant étant une projection calculée ou mise en cache. Voir [database.md](database.md) §11.

### Sales
Le cœur transactionnel du point de vente : `Sale`, `SaleItem`, paiement (y compris paiement mixte), remise, annulation, remboursement. Orchestre les effets de bord (décrément de stock via `Inventory`, écriture dans `CashRegister`) au sein d'une transaction DB.

### CashRegister
Session de caisse (ouverture/fermeture, fond de caisse) et mouvements de caisse (`CashTransaction`), qu'ils soient liés à une vente ou non (dépense, apport, retrait). Séparé de `Sales` car une boutique peut avoir des mouvements de caisse sans vente (ex: retrait pour la banque).

### Customers
Annuaire client partagé, référencé par `Sales`, `Appointments` et `Orders`. Reste volontairement simple (pas de CRM avancé à ce stade) : identité, contact, notes, éventuel programme de fidélité en champ extensible.

### Suppliers
Annuaire fournisseur, référencé par les entrées de stock (`StockMovement` de type achat) dans `Inventory`.

### Employees
La fiche employé (nom, poste, planning, rémunération éventuelle) est distincte du `StoreUser` : un employé peut ne jamais se connecter à l'application (ex: personnel de ménage) alors qu'un `StoreUser` est nécessairement un compte applicatif. Un `Employee` peut optionnellement être lié à un `StoreUser` s'il a un accès.

### Appointments
Prise de rendez-vous : `Appointment` liant un `Service` (Catalog), un `Employee` et un `Customer`, avec gestion de créneaux/disponibilité. Module central pour les métiers de service (coiffeur, salon de beauté), inutile pour un supermarché — d'où son activation conditionnée par `Features`.

### Orders
Workflow de commande *avant* finalisation : commande de table (restaurant), commande à emporter/livraison. Une `Order` a un cycle de vie (en préparation, prête, servie/livrée) et se résout en `Sale` au moment du paiement. Distinct de `Sales` qui est la transaction déjà finalisée.

### Reports
Lecture seule : agrège les données des autres modules (ventes par période, marges, rotation de stock, présence employé) et gère d'éventuels `ReportPreset` sauvegardés par un utilisateur. Ne possède pas de données métier primaires — dépend en lecture de tous les autres modules, jamais l'inverse.

### Subscriptions
`Plan` (catalogue des offres SaaS), `Subscription` (l'abonnement actif d'un `Business`), et les limites/features associées à un plan. Voir [subscriptions.md](subscriptions.md).

### Notifications
Journal des notifications envoyées (email, push, SMS futur) et préférences de canal par utilisateur, construit sur le système de notifications natif de Laravel.

## Règles de dépendance entre modules

> **Assouplissement décidé lors de l'audit du 2026-09-06** (voir [audit-2026-09.md](audit-2026-09.md)) : la règle 1 ci-dessous, dans sa version initiale, imposait un `Contract` `Shared` pour la moindre lecture entre modules — un coût disproportionné pour une équipe de 2 développeurs. La règle est reformulée pour ne contraindre que ce qui protège réellement l'intégrité du système : les écritures qui doivent respecter les règles métier d'un autre module.

1. **Lecture** : un module peut dépendre directement d'un `Model` Eloquent d'un autre module en lecture seule (ex: `Sales` peut faire `Customer::find($id)` sans détour) — imposer une interface pour chaque relation de lecture triviale serait de la sur-ingénierie pour la taille de cette équipe.
2. **Écriture** : un module ne modifie **jamais** directement, par une requête Eloquent brute, une donnée dont les règles métier appartiennent à un autre module (ex: `Sales` n'exécute jamais `Product::decrement('current_stock', ...)` lui-même — il appelle un service exposé par `Inventory`, qui seul connaît les règles de verrouillage/concurrence de §11 de [database.md](database.md)). C'est cette règle, pas la précédente, qui préserve la possibilité d'extraire un module plus tard.
3. Le contrat `Sellable` (`Shared\Contracts`) reste la bonne solution quand un module doit traiter plusieurs types d'un autre module de façon polymorphique (Product/Service) — un `Contract` se justifie par ce besoin de polymorphisme, pas par principe.
4. Les dépendances autorisées vont uniquement "vers le bas" de cette liste (approximativement dans l'ordre de dépendance naturelle) : `Tenancy`/`Authorization`/`Features` sont dépendus par tous ; `Catalog` est dépendu par `Inventory`, `Sales`, `Orders`, `Appointments` ; `Sales`/`Appointments`/`Orders` sont dépendus par `Reports`.
5. `Reports` ne modifie jamais l'état d'un autre module (lecture seule stricte).

Ces règles ne sont pas imposées par un outil au démarrage du projet (pas de linter d'architecture configuré) ; elles sont une convention d'équipe à faire respecter en revue de code tant que le volume ne justifie pas d'outiller la vérification (ex: `deptrac`).
