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

> **Implémenté (2026-10-06).** Feature `fournisseurs`.
>
> - **Fournisseurs** (`fournisseurs`) : CRUD `/api/boutiques/{store}/fournisseurs`, soft delete. La ressource expose `total_achats` et `solde_du` (somme des achats − somme réglée).
> - **Achats** (`achats`, `lignes_achat`) : `POST /achats` crée une réception de marchandise. `PurchaseService` (seul point d'écriture) entre les quantités en stock (`InventoryService::addStock()`, type `achat`, référence = l'achat), ou **initialise** le stock d'un produit jamais stocké. Il met aussi à jour `produits.prix_achat` avec le coût unitaire, sauf si `mettre_a_jour_prix_achat=false`. Une boutique sans la feature `stock` enregistre l'achat sans mouvement de quantité (même règle que Sales). Pas de PUT/DELETE : historique append-only.
> - **Règlements** (`paiements_achat`) : à la création (`paiement`) ou ensuite (`POST /achats/{achat}/paiements`), total ou partiel, jamais au-delà du reste dû (`PAIEMENT_INVALIDE`). Mode `caisse` : sortie de la session ouverte de `caisse_id` (`CashRegisterService::recordExpense()`, mouvement `sortie` référencé, `SOLDE_CAISSE_INSUFFISANT` / `AUCUNE_SESSION_CAISSE_OUVERTE`). Mode `externe` : payé hors caisse. Sans règlement, l'achat est à crédit (`statut_paiement` : `paye` / `partiel` / `non_paye`).
> - Tout-ou-rien en transaction, verrous dans le même ordre que Sales (Inventory puis CashRegister). Idempotence par `cle_idempotence`.
> - Permissions : `fournisseurs.{voir,creer,modifier,supprimer}`, `achats.{voir,creer}`. Propriétaire/administrateur/gérant : tout ; caissier : `fournisseurs.voir` seulement. Accordées aux boutiques existantes par migration.
> - Reste à faire : annulation/retour d'un achat, coût moyen pondéré.

### Employees
La fiche employé (nom, poste, planning, rémunération éventuelle) est distincte du `StoreUser` : un employé peut ne jamais se connecter à l'application (ex: personnel de ménage) alors qu'un `StoreUser` est nécessairement un compte applicatif. Un `Employee` peut optionnellement être lié à un `StoreUser` s'il a un accès.

> **Implémenté (2026-10-07).** Feature `employes`.
>
> - **Employés** (`employes`) : CRUD `/api/boutiques/{store}/employes`, soft delete. Nom, poste, téléphone, adresse, date d'embauche, `salaire` + `periodicite_salaire` (`mensuel`/`hebdomadaire`/`journalier`, requise si un salaire est saisi), notes, actif. `utilisateur_id` facultatif : doit être membre de la boutique (`utilisateurs_boutique`), un seul employé par compte. La ressource expose `paye_ce_mois` (somme versée depuis le 1er du mois).
> - **Paiements** (`paiements_employe`) : `POST /employes/{employee}/paiements`, `type` `salaire`/`avance`/`prime`, `periode` libre (« Octobre 2026 »). Mode `caisse` : sortie de la session ouverte (`CashRegisterService::recordExpense()`, mouvement `sortie` référencé `paiement_employe`). Mode `externe` : hors caisse. Tout-ou-rien ; erreurs `SOLDE_CAISSE_INSUFFISANT` / `AUCUNE_SESSION_CAISSE_OUVERTE`. Append-only.
> - Permissions : `employes.voir`, `employes.gerer` — propriétaire, administrateur, gérant. Caissier et employé : aucun accès (salaires confidentiels). Accordées aux boutiques existantes par migration.
> - Reste à faire : planning, retenue automatique des avances sur la paie, prestations par employé (préparé pour Appointments).

### Appointments
Prise de rendez-vous : `Appointment` liant un `Service` (Catalog), un `Employee` et un `Customer`, avec gestion de créneaux/disponibilité. Module central pour les métiers de service (coiffeur, salon de beauté), inutile pour un supermarché — d'où son activation conditionnée par `Features`.

> **Implémenté (2026-10-08).** Feature `rendez_vous` (dépend de `services` et `employes`).
>
> - Table `rendez_vous` : `service_id`, `employe_id` (nullable : « n'importe qui »), `client_id` **ou** `nom_client`/`telephone_client` (réservation au téléphone), `debut_le`, `fin_le`, `statut` (`prevu`, `confirme`, `termine`, `annule`, `absent`), `notes`, `motif_annulation`, `vente_id`.
> - `fin_le` = `debut_le` + `services.duree_minutes` (30 min par défaut), ou `duree_minutes` forcée. Déplacer garde la durée ; changer de service reprend celle du service.
> - **Non-chevauchement par employé** dans `AppointmentService`, en transaction avec `lockForUpdate()` sur la ligne `employes` (sérialise les réservations concurrentes). Bloquent le créneau : `prevu`, `confirme`, `termine`. Deux créneaux adjacents (fin = début) sont acceptés. Erreur `CRENEAU_INDISPONIBLE`.
> - Routes `/api/boutiques/{store}/rendez-vous` : liste (`du`/`au` en instants ISO 8601 — l'application envoie les bornes de la journée locale —, `employe_id`, `client_id`, `statut`), création, détail, `PUT` (déplacer/modifier), `POST /{id}/statut` (`confirme`, `termine` + `vente_id` facultatif, `absent`), `POST /{id}/annuler`. Un rendez-vous terminé, annulé ou absent ne change plus (`RENDEZ_VOUS_CLOS`).
> - Permissions : `rendez_vous.{voir,creer,modifier,annuler}`. Propriétaire/administrateur/gérant et caissier (accueil) : tout ; employé : `voir`. Accordées aux boutiques existantes par migration.
> - Reste à faire : horaires d'ouverture et jours de congé par employé, rappels (module Notifications).

### Orders
Workflow de commande *avant* finalisation : commande de table (restaurant), commande à emporter/livraison. Une `Order` a un cycle de vie (en préparation, prête, servie/livrée) et se résout en `Sale` au moment du paiement. Distinct de `Sales` qui est la transaction déjà finalisée.

> **Implémenté (2026-10-09).** Features `commandes` et `tables` (qui dépend de `commandes`).
>
> - **Tables** (`tables_salle`) : nom, capacité, actif — `GET/POST /tables`, `PUT /tables/{table}` (désactiver plutôt que supprimer). `occupee` et la commande en cours sont **dérivés** des commandes ouvertes (`DiningTable::openOrder()`), jamais saisis. Une seule commande ouverte par table (`TABLE_OCCUPEE`).
> - **Commandes** (`commandes`, `lignes_commande`) : `type` `sur_place` (table) / `a_emporter` / `livraison` (adresse) / `depot` (atelier, pressing : `date_promise`). Client enregistré ou `nom_client`/`telephone_client`. Lignes = produit (+ `mode_prix`) **ou** service, avec `note` ; prix figé à la commande à titre indicatif.
> - Cycle : `en_attente` → `en_preparation` → `prete` → `servie` (librement, tant que la commande est ouverte), puis `payee` (encaissement) ou `annulee`. Une commande close ne change plus (`COMMANDE_CLOSE`).
> - **Encaisser** (`POST /commandes/{id}/encaisser`, `caisse_id`, `montant_remise`) : `OrderService` appelle `SaleService::checkout()` — prix recalculés serveur, stock, caisse, mêmes codes d'erreur que Sales — avec la clé d'idempotence `commande-{id}` (un double appui ne crée jamais deux ventes), puis lie `vente_id`. Tout-ou-rien : une erreur laisse la commande ouverte.
> - Permissions : `commandes.{voir,creer,modifier,annuler}`, `tables.gerer`. Encaisser exige aussi `ventes.creer`. Propriétaire/administrateur/gérant : tout ; caissier : tout sauf `tables.gerer` ; employé (serveur, cuisine) : voir/créer/modifier. Accordées aux boutiques existantes par migration.
> - Reste à faire : acompte à la commande (pressing), impression du bon de cuisine, livraison suivie.

### Reports
Lecture seule : agrège les données des autres modules (ventes par période, marges, rotation de stock, présence employé) et gère d'éventuels `ReportPreset` sauvegardés par un utilisateur. Ne possède pas de données métier primaires — dépend en lecture de tous les autres modules, jamais l'inverse.

### Subscriptions
`Plan` (catalogue des offres SaaS), `Subscription` (l'abonnement actif d'un `Business`), et les limites/features associées à un plan. Voir [subscriptions.md](subscriptions.md).

### Notifications
Journal des notifications envoyées (email, push, SMS futur) et préférences de canal par utilisateur, construit sur le système de notifications natif de Laravel.

> **Implémenté (2026-10-10) — centre de notifications dans l'application (canal `database`).**
>
> - Table standard Laravel `notifications` (nom imposé par le framework). Une seule classe `StoreAlert` : `data` = `boutique_id`, `categorie`, `titre`, `message`, `lien` (`{ecran: stock|commande|rendez_vous, id}`).
> - **Découplage par événements** : Inventory émet `StockLevelChanged` (depuis `applyDelta()`, donc pour tout mouvement), Orders `OrderStatusChanged`, Appointments `AppointmentBooked` — tous `ShouldDispatchAfterCommit` (rien n'est notifié si la transaction est annulée). Notifications les écoute ; les autres modules ne le connaissent pas.
> - Alertes : **stock faible / rupture** au franchissement du seuil (une fois, pas à chaque vente) → `stock.ajuster` ; **commande prête** → `commandes.voir` sauf l'auteur ; **nouveau rendez-vous** → compte lié à l'employé ; **rappel** 1 h avant → compte de l'employé, sinon `rendez_vous.modifier`.
> - Destinataires : `StoreRecipients::withPermission()` (membres actifs ayant la permission dans CETTE boutique — contexte Spatie posé puis restauré).
> - Rappels : commande `notifications:rappels-rendez-vous`, planifiée toutes les 5 minutes, idempotente. **Exige le planificateur** : `* * * * * php artisan schedule:run` en production, `php artisan schedule:work` en développement.
> - API : `GET /boutiques/{store}/notifications` (`non_lues=1`), `GET …/compteur`, `POST …/{id}/lire`, `POST …/tout-lire` — notifications de l'utilisateur connecté, pour cette boutique uniquement.
> - Heures des messages au fuseau `boutiques.fuseau_horaire`.
> - Reste à faire : push (application fermée) — ajouter un canal dans `StoreAlert::via()` avec `expo-notifications`, qui exige un build de développement (plus disponible dans Expo Go) ; préférences de canal par utilisateur.

## Règles de dépendance entre modules

> **Assouplissement décidé lors de l'audit du 2026-09-06** (voir [audit-2026-09.md](audit-2026-09.md)) : la règle 1 ci-dessous, dans sa version initiale, imposait un `Contract` `Shared` pour la moindre lecture entre modules — un coût disproportionné pour une équipe de 2 développeurs. La règle est reformulée pour ne contraindre que ce qui protège réellement l'intégrité du système : les écritures qui doivent respecter les règles métier d'un autre module.

1. **Lecture** : un module peut dépendre directement d'un `Model` Eloquent d'un autre module en lecture seule (ex: `Sales` peut faire `Customer::find($id)` sans détour) — imposer une interface pour chaque relation de lecture triviale serait de la sur-ingénierie pour la taille de cette équipe.
2. **Écriture** : un module ne modifie **jamais** directement, par une requête Eloquent brute, une donnée dont les règles métier appartiennent à un autre module (ex: `Sales` n'exécute jamais `Product::decrement('current_stock', ...)` lui-même — il appelle un service exposé par `Inventory`, qui seul connaît les règles de verrouillage/concurrence de §11 de [database.md](database.md)). C'est cette règle, pas la précédente, qui préserve la possibilité d'extraire un module plus tard.
3. Le contrat `Sellable` (`Shared\Contracts`) reste la bonne solution quand un module doit traiter plusieurs types d'un autre module de façon polymorphique (Product/Service) — un `Contract` se justifie par ce besoin de polymorphisme, pas par principe.
4. Les dépendances autorisées vont uniquement "vers le bas" de cette liste (approximativement dans l'ordre de dépendance naturelle) : `Tenancy`/`Authorization`/`Features` sont dépendus par tous ; `Catalog` est dépendu par `Inventory`, `Sales`, `Orders`, `Appointments` ; `Sales`/`Appointments`/`Orders` sont dépendus par `Reports`.
5. `Reports` ne modifie jamais l'état d'un autre module (lecture seule stricte).

Ces règles ne sont pas imposées par un outil au démarrage du projet (pas de linter d'architecture configuré) ; elles sont une convention d'équipe à faire respecter en revue de code tant que le volume ne justifie pas d'outiller la vérification (ex: `deptrac`).
