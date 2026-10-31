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

> **Facture et frais (2026-11-11).** Achat : `numero_facture_fournisseur`, `reference_commande` (cherchables), `frais_annexes` + `detail_frais` (transport, douane…) répartis au prorata de la valeur des lignes dans `lignes_achat.cout_revient_unitaire` — qui devient le prix d'achat du produit et le coût des appareils reçus. Les frais ne s'ajoutent pas à la dette fournisseur.

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

> **Salon de beauté (2026-11-19).** Nouvelles features `consommables`, `forfaits`, `fidelite` et `commissions` (option désactivée par défaut, activée par le gérant dans Paramètres du module). Le domaine `salon_beaute` inclut aussi produits, catégories, stock, fournisseurs, `conservation`, dépenses, équipements, rapport de gestion et promotions (migration `add_salon_beaute_features`, idempotente). La Coiffure ne change pas.
>
> - **Statuts** : `en_attente` (demande à confirmer, créneau bloqué) et `en_cours` (`commence_le`) s'ajoutent ; actifs = `en_attente`, `prevu`, `confirme`, `en_cours`.
> - **Plusieurs prestations** : table `prestations_rendez_vous` (service, employé éventuel, durée, prix, ordre) ; `services[]` à la réservation, durée = somme ; `service_id` reste la première. Les rendez-vous existants ont reçu leur ligne (migration). Chaque employé est vérifié sur tout le créneau.
> - **Planning** (`PlanningService`, chaque règle seulement si configurée) : horaires d'ouverture (`boutiques.parametres.horaires_ouverture`, `GET|PUT /parametres/salon`) → `SALON_FERME` ; horaires et pause par employé (`horaires_employes`), congés/absences (`absences_employes`) → `EMPLOYE_INDISPONIBLE` ; habilitations (`services_employes`, aucune = toutes) → `EMPLOYE_NON_HABILITE` ; chevauchement → `CRENEAU_INDISPONIBLE`. `GET|PUT /employes/{id}/planning`, `POST|DELETE /employes/{id}/absences`. `GET /rendez-vous/creneaux?date=&services=&employe_id=` : heures libres par employé habilité (pas de 15 min). Filtres de l'agenda : `employe_id` (aussi sur les prestations), `service_id`.
> - **Acompte** à la réservation (`acompte` + `caisse_id`, entrée de caisse référencée) ; à l'annulation `rembourser_acompte` (sinon acquis).
> - **Encaissement** `POST /rendez-vous/{id}/encaisser` : une vente (`SaleService`, clé `rendez-vous-{id}`) avec toutes les prestations (employé par ligne), produits ajoutés, remise, points ; acompte imputé (`deja_encaisse`) ; prestations couvertes par le forfait non facturées ; puis `termine`.
> - **Consommables** (`consommables_services`, champ `consommables` du service) : sortis du stock une seule fois, à l'encaissement, avec les mouvements de la vente → remis par l'annulation. Coût exposé (`cout_consommables`). Service : `image` (`POST|DELETE /services/{id}/image`), `commission_type` / `commission_valeur`.
> - **Forfaits** (`forfaits_prestations`, `services_forfait`, `forfaits_clients`, `seances_forfait`) : CRUD `/forfaits`, `POST /forfaits/{id}/vendre` (vente avec une ligne `forfait_id`), `GET /forfaits-clients?client_id=&statut=actif|epuise|expire|annule`. Rendez-vous `forfait_client_id` (client, validité, séances, prestation incluse vérifiés : `FORFAIT_INVALIDE`) ; séance décomptée uniquement au passage à `termine`, une seule fois ; vente annulée → forfait annulé.
> - **Commissions** (`commissions`) : calculées à l'encaissement par ligne de prestation avec employé — règle de la prestation (% ou montant fixe), sinon `employes.taux_commission` ; vente annulée → `annulee` (ou ligne négative si déjà payée). `GET /commissions`, `POST /commissions/payer` → paiement de paie de type `commission` (refusé par le paiement générique).
> - **Fidélité** (`clients.points_fidelite`, `mouvements_fidelite`) : barème `parametres.fidelite` (`montant_par_point`, `valeur_point`) ; points gagnés sur le total, `points_utilises` convertis en remise (`ventes.remise_fidelite`, `POINTS_INSUFFISANTS`), repris à l'annulation ; `GET /clients/{id}/fidelite`, `POST …/ajustement`.
> - **Clients** : préférences beauté dans `preferences` (`type_peau`, `type_cheveux`, `allergies`, `prestations_preferees`).
> - Rapport de gestion : section `salon` (prestations réalisées et plus demandées, CA prestations / produits / forfaits, par employé, taux d'annulation et d'absence, occupation si horaires, coût des consommables, commissions dues, clients nouveaux / réguliers (≥ 3 visites en 90 j) / inactifs (> 60 j)). Dépenses : `internet`, `mobilier` ; équipements : `fauteuil`, `table_massage`, `lampe_uv`, `appareil_soin`, `seche_cheveux`.

> **Coiffure (2026-11-20).** Le domaine `coiffure` reçoit les outils du salon (produits, catégories, stock, fournisseurs, lots, dépenses, équipements, rapport de gestion, promotions, consommables, forfaits, fidélité ; commissions en option) — migration `add_coiffure_features`, idempotente.
>
> - **Formules combinées** (Catalog `composants_formule`, `services.est_formule`) : prix et durée globaux (durée vide = somme des composants, `Service::effectiveDuration()`) ; consommables et coût repris des composants si la formule n'en a pas ; un employé habilité à tous les composants peut réaliser la formule. La fiche prestation expose `employes_habilites` / `employes_sans_restriction`.
> - **Consommation réelle** : table `consommations_prestations` (vente, produit, `quantite_prevue`, `quantite`, `cout_unitaire`, `ajustee`), écrite une fois à l'encaissement. `POST /rendez-vous/{id}/encaisser` accepte `consommations[]` (remplace les quantités prévues, permission `stock.ajuster`) ; `GET /rendez-vous/{id}/consommations` donne les quantités prévues. Les prestations couvertes par un forfait sont dans la vente à 0 (réalisées, produits consommés).
> - **Client sans rendez-vous** : `statut=en_cours` à la création (`commence_le`). Paiement partiel à l'encaissement : `mode_paiement=credit` + `acompte` (reste au compte client).
> - **Clients** : `sexe`, `date_naissance` ; `GET /clients/{id}/statistiques` (visites, total dépensé, prestations, produits achetés). **Employés** : `photo` (`POST|DELETE /employes/{id}/photo`), `specialites`, `GET /employes/{id}/activite?du=&au=` (prestations encaissées, CA, rendez-vous, commissions).
> - **Dépenses** : `moyen_paiement` (hors caisse), justificatif photo sur disque privé (`POST|GET /depenses/{id}/justificatif`). **Équipements** : `cout_acquisition` ; types `tondeuse`, `lisseur`, `casque_chauffant`, `bac_lavage`.
> - **Rapports** : périodes `cette_annee` et `personnalisee` (`du`, `au`, un an au plus). Tableau de bord : `articles_vendus` ne compte plus que les produits ; `prestations`, `chiffre_affaires_prestations` et section `salon` (rendez-vous du jour par statut, à venir, clients reçus, meilleures prestations, employés les plus actifs). Rapport de gestion : coût des consommables et `produits_plus_consommes` lus dans `consommations_prestations`.

### Orders
Workflow de commande *avant* finalisation : commande de table (restaurant), commande à emporter/livraison. Une `Order` a un cycle de vie (en préparation, prête, servie/livrée) et se résout en `Sale` au moment du paiement. Distinct de `Sales` qui est la transaction déjà finalisée.

> **Implémenté (2026-10-09).** Features `commandes` et `tables` (qui dépend de `commandes`).
>
> - **Tables** (`tables_salle`) : nom, capacité, actif — `GET/POST /tables`, `PUT /tables/{table}` (désactiver plutôt que supprimer). `occupee` et la commande en cours sont **dérivés** des commandes ouvertes (`DiningTable::openOrder()`), jamais saisis. Une seule commande ouverte par table (`TABLE_OCCUPEE`).
> - **Commandes** (`commandes`, `lignes_commande`) : `type` `sur_place` (table) / `a_emporter` / `livraison` (adresse) / `depot` (atelier, pressing : `date_promise`). Client enregistré ou `nom_client`/`telephone_client`. Lignes = produit (+ `mode_prix`) **ou** service, avec `note` ; prix figé à la commande à titre indicatif.
> - Cycle : `en_attente` → `en_preparation` → `prete` → `servie` (librement, tant que la commande est ouverte), puis `payee` (encaissement) ou `annulee`. Une commande close ne change plus (`COMMANDE_CLOSE`).
> - **Encaisser** (`POST /commandes/{id}/encaisser`, `caisse_id`, `montant_remise`) : `OrderService` appelle `SaleService::checkout()` — prix recalculés serveur, stock, caisse, mêmes codes d'erreur que Sales — avec la clé d'idempotence `commande-{id}` (un double appui ne crée jamais deux ventes), puis lie `vente_id`. Tout-ou-rien : une erreur laisse la commande ouverte.
> - Permissions : `commandes.{voir,creer,modifier,annuler}`, `tables.gerer`. Encaisser exige aussi `ventes.creer`. Propriétaire/administrateur/gérant : tout ; caissier : tout sauf `tables.gerer` ; employé (serveur, cuisine) : voir/créer/modifier. Accordées aux boutiques existantes par migration.
> - **Acompte** (2026-10-30) : `POST /commandes/{id}/acompte` (`montant`, `caisse_id`, même droit que l'encaissement) — la somme entre en caisse (mouvement `entree` référencé `commande`) et s'ajoute à `commandes.montant_acompte`, jamais au-delà du total (`ACOMPTE_INVALIDE`, y compris si une ligne retirée ou réduite ferait passer le total sous l'acompte). À l'encaissement, la vente garde son total complet (chiffre d'affaires) mais seul le reste entre en caisse (`SaleService::checkout()`, option interne `deja_encaisse`). Annulation d'une commande avec acompte : remboursement depuis `caisse_id`, ou `conserver_acompte=true`. La ressource expose `acompte` et `reste_a_payer`.
> - Boucherie (2026-10-30) : `commandes` est un défaut du domaine (préparations commandées, migration de données `enable_orders_for_butcher_domain`) ; une commande à emporter y porte une date de préparation (`date_promise`). Les quantités au poids (kg, g) sont décimales.
> - Reste à faire : impression du bon de cuisine, livraison suivie.

> **Restaurant (2026-11-15).** Domaine Restaurant : catégories, fournisseurs, conservation, dépenses, équipements, rapport de gestion et les nouvelles features `supplements`, `recettes`, `cuisine`, `reservations` inclus par défaut (migration de données `add_restaurant_features`, idempotente, autres métiers inchangés).
>
> - **Carte** (Catalog) : `produits.type_article` (`plat`, `boisson`, `ingredient`, `formule`, `autre`), `temps_preparation_minutes`, `disponible` (épuisé du jour, distinct de `actif` : `PRODUIT_INDISPONIBLE` à la commande), suppléments payants `supplements_produit` (`supplement_ids`), qui deviennent des lignes de commande rattachées (`ligne_parent_id`) au prix de leur produit (`SUPPLEMENT_INVALIDE` sinon). Filtres `type_article`, `disponible`.
> - **Recettes** (Catalog, `recettes`, `ingredients_recette`) : `GET /recettes`, `GET/PUT/DELETE /produits/{id}/recette` (portions, ingrédients, `mettre_a_jour_prix_achat`). `RecipeService` : coût de revient par portion (prix d'achat des ingrédients ou coût de leur propre recette, 3 niveaux, boucle refusée `RECETTE_INVALIDE`), reporté sur `produits.prix_achat`. À la vente (`SaleService`, feature `recettes`), un plat avec recette fait sortir ses ingrédients (mouvement `vente` référencé sur la vente, ingrédient non suivi ignoré) au lieu de lui-même ; l'annulation remet exactement ce que la vente a sorti. Droits : lecture `produits.voir`, écriture `fiches_preparation.gerer`.
> - **Salle** : `tables_salle.espace`, `a_nettoyer` ; statut dérivé `disponible` / `occupee` / `attente_paiement` (addition demandée, `POST /commandes/{id}/addition`) / `reservee` (réservation confirmée de −30 min à +1 h) / `a_nettoyer` (après paiement, annulation ou déplacement ; remis à zéro à l'ouverture d'une commande). Commande : `serveur_id` (défaut : celui qui la prend), `nombre_couverts`, déplacement par `PUT table_id`, `POST /commandes/{id}/regrouper` (`commande_ids` : lignes et acomptes rejoignent la commande, les autres sont closes).
> - **Cuisine** : `lignes_commande.statut_cuisine` (`a_preparer` → `en_preparation` → `prete` → `servie`, ou `indisponible`, horodatés). `GET /cuisine` (`boissons=0`) : commandes ouvertes avec plats actifs, `urgente` si l'attente dépasse le temps de préparation (20 min par défaut). `POST /commandes/{id}/lignes/{ligne}/cuisine` (`statut`, `marquer_epuise`) ; la commande suit (en préparation, prête, servie). Un plat indisponible (et ses suppléments) sort de l'addition. Bon de cuisine partagé/imprimé depuis l'application.
> - **Encaissement** : `mode_paiement` (`especes`, `mobile_money`, `carte`, `virement`, `credit` avec client, sans acompte) sur `encaisser` — corrige l'encaissement toujours en espèces. Addition divisée `POST /commandes/{id}/diviser` (`lignes[{ligne_id, quantite}]`, ligne coupée si quantité partielle) : une vente par part, `lignes_commande.vente_id`, `deja_regle` ; l'encaissement final règle le reste (et clôt sans vente si tout est réglé).
> - **Réservations** (`reservations`) : `GET /reservations` (`date`, `a_venir`, `statut`), `POST`, `PUT /{id}` (dont `statut` `confirmee`/`arrivee`/`annulee`/`non_honoree`). Une table, une réservation confirmée par créneau de ±2 h (`TABLE_DEJA_RESERVEE`). Droits : `commandes.voir` / `commandes.creer`.
> - **Rapport de gestion** : section `restaurant` (commandes de la période et en cours, temps moyen de préparation, ventes par type d'article, boissons les plus vendues, tables occupées/libres, réservations du jour). Dépenses : `gaz`, `charbon`, `produits_entretien`, `communication`. Équipements : four, cuisinière, friteuse, grill, machine à café, mixeur.


> **Pressing (2026-11-21).** Feature `articles_depot` (domaine `pressing`, avec produits, stock, fournisseurs, catégories, dépenses, matériel, rapport de gestion, consommables) — migration `add_pressing_features`. Sans la feature, les commandes (restaurant, boucherie, atelier) ne changent pas.
>
> - **Tarifs** (Catalog) : `services.unite_tarif` (piece, kg, m2, paire, lot), `delai_heures`, `consignes` ; grille `tarifs_articles_service` (prix par type d'article). Ligne de commande `type_article` → prix de la grille, conservé à l'encaissement (`prix_impose`, option interne de SaleService).
> - **Pièces** (`articles_depot`) : une par pièce, code unique `00042-03`, type, couleur, marque, état, défauts, consignes, photo (`POST /commandes/{id}/articles/{a}/photo`), statut (recu, en_traitement, controle, pret, remis, annule), employé. `lignes[].articles[]` décrit les pièces ; sinon une par unité (pièce/paire entière) ou un lot. `PUT /commandes/{id}/articles/{a}` ; toutes prêtes → commande « prête ». `GET /commandes-articles?code=` ; la recherche des commandes couvre les codes. Filtres `en_retard`, `bientot`, `client_id`, `responsable_id`.
> - **Étapes** : statut `controle` ajouté ; `servie` (remise) impossible par simple statut (`REMISE_REQUISE`). Historique append-only `historique_commandes` (statut, remise, paiement avec montant, livraison, affectation, consommation ; qui, quand).
> - **Consommation** au premier passage « prête » (`consommation_faite`), quantités corrigeables (`consommations[]`, permission `stock.ajuster`), mouvement `consommation` référencé sur la commande, trace `consommations_prestations.commande_id`. La vente du dépôt ne consomme plus rien (`sans_consommation_prestations`). Annulation avant « prête » : rien.
> - **Payé ≠ remis** : `paiement` = total, payé (acomptes + vente), solde, statut (non_paye, partiel, paye, credit). « Encaisser » crée la vente sans clore. `POST /commandes/{id}/remise` (`article_ids` ou tout, `paiement`, `differer_paiement`, `mode` retrait|livraison) → `remises_depot` ; dernière remise : solde exigé (`SOLDE_A_REGLER`) sauf réglage `parametres.pressing.solde_avant_remise=false` (`GET|PUT /parametres/pressing`) ou personne `credits.gerer` (reste sur le compte client). Close (`payee`) seulement si remise et réglée.
> - **Livraison** interne : type `livraison`, `statut_livraison` (a_livrer, en_livraison, livree), `livreur_id`, `livree_le`. **Responsable** : `responsable_id`. Client : `instructions_remise`.
> - **Alertes** : `notifications:depots` (toutes les heures) — dépôt bientôt dû / en retard ; « dépôt prêt » rappelle le solde.
> - **Rapports** : section `pressing` (tableau de bord et gestion) — reçues, à traiter, en traitement, prêtes, remises, en retard, facturé ≠ encaissé, soldes à recouvrer, prestations, consommables, pièces par employé, clients réguliers. Activité employé : pièces en cours / traitées.

### Transformations
Transformation du stock : des matières sont consommées, des produits sont obtenus, et le coût des matières est réparti sur les produits obtenus. Couvre la **découpe** d'une carcasse en morceaux et les **préparations** (haché, merguez, brochettes…). Ce n'est jamais un achat : aucune dette fournisseur, aucune caisse.

> **Implémenté (2026-10-31).** Features `decoupe` et `preparations` (dépendent de `stock` et `produits`), défaut du seul domaine Boucherie (migration de données `add_transformation_features`).
>
> - **Opérations** (`transformations`, `lignes_transformation`) : `POST /decoupes` ou `/preparations` avec `entrees` (matières, `cout_total` ou `cout_unitaire` facultatif, sinon `produits.prix_achat`, sinon `COUT_INCONNU`) et `sorties` (produits obtenus). `TransformationService` (seul point d'écriture) sort les matières (`transformation_sortie`) et entre les produits (`transformation_entree`, stock créé à 0 pour un nouveau morceau), verrous par `produit_id` croissant comme Sales. Un produit ne peut être à la fois consommé et obtenu (`TRANSFORMATION_INVALIDE`). Idempotence par `cle_idempotence`.
> - **Coûts** : coût total = matières + `frais_annexes`. Répartition `valeur` (défaut, au prorata quantité × prix de vente) ou `poids` ; repli automatique sur le poids si un produit obtenu n'a pas de prix de vente. Le dernier produit prend l'arrondi : la somme répartie égale toujours le coût total. Le coût de revient devient `produits.prix_achat` (sauf `mettre_a_jour_prix_achat=false`), ce qui alimente marges et bénéfice estimé.
> - **Poids et pertes** : `poids_entree`/`poids_sortie` (kg, lignes en kg ou g seulement, les pièces n'y comptent pas), `perte` = écart, `taux_perte` exposé. La ressource expose aussi `valeur_vente`, `marge_potentielle` (globale et par ligne).
> - **Fiches de préparation** (`fiches_preparation`, `ingredients_fiche`) : CRUD `/fiches-preparation` — produit fini, quantité par fournée, ingrédients, `duree_conservation_jours` (d'où `consommer_avant` de la préparation). Soft delete ; les opérations passées gardent leurs lignes.
> - **Annulation** `POST /{type}/{id}/annuler` (`motif`) : mouvements inverses référencés, refusée si une partie des produits obtenus est déjà sortie (`STOCK_INSUFFISANT`). Historique append-only, ligne conservée (`annulee`).
> - Permissions : `transformations.{voir,creer,annuler}`, `fiches_preparation.gerer`. Propriétaire/administrateur/gérant : tout ; employé (boucher) : voir, créer ; caissier : voir. Accordées aux boutiques existantes par migration.
> - Reste à faire : lots et dates limites suivis en stock (phase 3), rapports des pertes de découpe.

### Conservation
Lots de stock datés, dates limites, alertes de péremption et pertes valorisées. Lit les mouvements de stock (événement `StockMovementRecorded`) sans qu'Inventory le connaisse.

> **Implémenté (2026-11-01).** Feature `conservation` (dépend de `stock` et `produits`), défaut du seul domaine Boucherie (migration de données `add_conservation_feature`).
>
> - **Produit** : `produits.conservation` (`frais` / `congele` / `transforme`) et `duree_conservation_jours`, facultatifs (`ProductRules`, donc aussi l'import Excel).
> - **Lots** (`lots_stock`) : projection du journal des mouvements, jamais la source des quantités (`stocks`). Toute entrée (`quantite` > 0 : achat, initial, retour, découpe, préparation, transfert reçu, inventaire en hausse) crée un lot ; `date_limite` = `consommer_avant` de la transformation, sinon entrée + `duree_conservation_jours`, sinon aucune. Toute sortie consomme les lots date limite la plus proche d'abord (puis les plus anciens) ; le stock antérieur au suivi n'a pas de lot. Écouteur synchrone `TrackStockLots`, dans la transaction du mouvement ; sans la feature il ne fait rien.
> - **API** : `GET /lots` (`produit_id`, `expire_dans`, `tous`), `GET /lots/alertes` (compteurs), `PUT /lots/{id}` (date de l'étiquette, conservation, note), `POST /lots/{id}/perte` (par défaut tout le reste, origine `peremption` ; mouvement `perte` référencé `lot_stock`, qui consomme ce lot en priorité). États : `perime`, `bientot` (≤ 2 jours), `ok`, `sans_date`.
> - **Pertes valorisées** : `mouvements_stock.origine_perte` (`peremption`, `avarie`, `conservation`, `manipulation`, `vol`, `autre`, facultative, aussi sur le mouvement manuel `perte`) et `cout_unitaire` (prix d'achat figé au moment de la perte). `GET /pertes?du=&au=` : valeur totale, par origine, et pertes de découpe (perte × coût matière du kilo).
> - **Alertes** : `conservation:alertes-peremption`, chaque jour à 7 h — lots bientôt périmés puis périmés, une notification par lot et par état, à `stock.ajuster`.
> - Permissions : celles du stock (`stock.voir` lit les lots ; `stock.ajuster` corrige, déclare une perte, consulte les pertes valorisées).
> - Reste à faire : rattacher un lot à un espace de conservation (équipements, phase 4).

### Expenses
Dépenses d'exploitation (électricité, eau, glace, transport, emballages, équipement, entretien, salaires, loyer, taxes, autre) — pas les achats de marchandise (Suppliers).

> **Implémenté (2026-11-02).** Feature `depenses`, défaut du seul domaine Boucherie.
>
> - `POST /depenses` (`categorie`, `libelle`, `montant`, `mode` `caisse`/`externe`, `caisse_id`, `depense_le`, `note`, `cle_idempotence`) : `ExpenseService` (seul point d'écriture) sort l'argent de la session ouverte via `CashRegisterService::recordExpense()` (mouvement `sortie` référencé `depense`) ; `AUCUNE_SESSION_CAISSE_OUVERTE`, `SOLDE_CAISSE_INSUFFISANT`, tout-ou-rien.
> - `GET /depenses` (`du`, `au`, `categorie`, `statut`), `GET /depenses/resume` : total de la période par catégorie (annulées exclues) **plus les salaires versés via Employees, lus et jamais ressaisis**.
> - `POST /depenses/{id}/annuler` (`motif`, `caisse_id`) : append-only, l'argent payé en caisse y revient (`entree` référencée). `DEPENSE_DEJA_ANNULEE`.
> - Permissions : `depenses.{voir,creer,annuler}`. Propriétaire/administrateur/gérant : tout ; caissier : voir, créer ; employé : rien.

### Equipment
Équipements (congélateur, chambre froide, réfrigérateur, vitrine réfrigérée, balance, scie, hachoir, trancheuse, autre), entretiens, pannes et réparations.

> **Implémenté (2026-11-02).** Feature `equipements`, défaut du seul domaine Boucherie.
>
> - CRUD `/equipements` (soft delete), filtres `type`, `statut`, `stockage=1` (espaces de conservation), `actif`. Fréquence d'entretien → `prochain_entretien_le` calculé à la création.
> - `POST /equipements/{id}/interventions` (`type` `panne`/`reparation`/`entretien`, `description`, `cout`, `prestataire`, `paiement`) : une panne met l'équipement « en panne », une réparation « en service », un entretien repousse l'échéance. Un coût payé devient une dépense « Entretien et réparations » via `ExpenseService` (si `depenses` est active), jamais saisie deux fois.
> - Lots : `lots_stock.equipement_id` (espace de conservation uniquement), `PUT /lots/{id}`, `GET /lots?equipement_id=`.
> - Alertes : `equipements:alertes-entretien` chaque jour à 7 h, une par équipement et par échéance, à `equipements.gerer`.
> - Permissions : `equipements.voir` (tout le personnel, qui peut aussi signaler une panne), `equipements.gerer` (créer, modifier, entretien, réparation : propriétaire, administrateur, gérant).
> - Reste à faire : relevés de température automatiques (matériel compatible requis).

### Devices
Appareils suivis à l'unité (électronique) : chaque exemplaire d'un produit `suivi_unitaire`, identifié par IMEI et/ou numéro de série.

> **Implémenté (2026-11-06).** Features `marques_modeles` (fiche technique du produit : `marque`, `modele`, `etat` neuf/occasion/reconditionné, `caracteristiques`, `fournisseur_id` habituel, tous facultatifs ; `GET /produits/marques`, filtres `marque`/`modele`/`etat`/`suivi_unitaire`) et `appareils` (dépend de `produits`, `stock`), défaut du domaine Électronique.
>
> - Tables `appareils` (statut `en_stock`/`vendu`/`defectueux`/`perdu`, IMEI unique par boutique, n° de série unique par boutique, coût d'achat, vente, ligne de vente, client) et `historique_appareils`. La quantité reste tenue par Inventory ; l'appareil dit lequel.
> - `DeviceService` (seul point d'écriture) : **achat** — IMEI facultatifs à la réception, mais un par unité s'ils sont donnés (`APPAREILS_REQUIS`, doublons `APPAREIL_EXISTANT`) ; **vente** — un produit suivi exige `appareil_ids` (autant que la quantité, entière), appareils en stock de ce produit (`APPAREIL_INDISPONIBLE`), rattachés à la vente, la ligne et le client ; **annulation** — de nouveau en stock ; **identification** du stock existant (`POST /appareils`, sans entrée en stock, jamais plus que les unités non identifiées) ou nouvel arrivage (`ajouter_au_stock`) ; **statut** — défectueux / perdu sortent du stock (perte valorisée `avarie` / `vol`), remis en stock y reviennent. 15 chiffres = IMEI, sinon numéro de série.
> - Sales, Suppliers et Orders appellent `DeviceService` ; sans la feature ou pour un produit non suivi, rien ne change (gestion en quantité).
> - `GET /appareils` (`recherche` IMEI/série, `produit_id`, `statut`, `client_id`), fiche avec historique. Permissions du stock : `stock.voir` consulte, `stock.ajuster` enregistre et change l'état ; vendre exige `ventes.creer`.

### Warranties
Garanties vendeur sur les articles vendus (électronique).

> **Implémenté (2026-11-08).** Feature `garanties` (dépend de `ventes`), défaut du domaine Électronique. Produit : `garantie_mois` (0–120, vide = sans garantie) et `conditions_garantie`.
>
> - Tables `garanties` (une par appareil vendu, sinon une par ligne ; n° `GAR-…`, `debut_le`/`fin_le`, statut `valide`/`annulee` ; l'état `active`/`expiree` est calculé), `reclamations_garantie` (`ouverte` → `en_cours` → `resolue`/`refusee`) et leur suivi.
> - `WarrantyService` : créées par `SaleService` après la vente, annulées avec la vente ou avec le retour de l'article (Returns). Réclamation refusée si la garantie n'est plus active.
> - `GET /garanties` (`recherche` n°/IMEI/série/ticket/client/téléphone, `etat` active/expiree/annulee/reclamation, `client_id`, `vente_id`), fiche, `POST /garanties/{id}/reclamations`, `PUT /garanties/{id}/reclamations/{claim}`. Permissions `garanties.voir` / `garanties.gerer`. Les lignes de vente exposent `garanties` (ticket : durée et date de fin).

### Returns
Retours d'articles vendus, partiels ou totaux ; l'échange = un retour suivi d'une nouvelle vente.

> **Implémenté (2026-11-09).** Feature `retours` (dépend de `ventes`), défaut du domaine Électronique. `ReturnService` seul point d'écriture.
>
> - Tables `retours` (n° `RET-…`, motif, `decision` remboursement/avoir, `etat_produits` revendable/défectueux, montant, `mode_remboursement` especes/hors_caisse) et `lignes_retour` (quantité, montant, `appareil_ids`).
> - Jamais plus que vendu (`QUANTITE_RETOUR_INVALIDE`) ; produit suivi : appareils exigés (`APPAREILS_REQUIS`), remis en stock ou marqués défectueux. Stock : entrée `retour`, puis sortie en perte si défectueux. Montant au prorata, remise globale comprise. Garanties des articles rendus annulées.
> - Remboursement en espèces = sortie de caisse (session ouverte exigée) ; hors caisse = rien en caisse ; avoir = écriture `avoir_retour` sur le compte client (vente avec client exigée, `CLIENT_REQUIS`).
> - `GET /retours` (`vente_id`, `client_id`), `POST /retours` (permission `ventes.annuler`). Les lignes de vente exposent `id` et `quantite_retournee`.

### Repairs
Réparations d'appareils confiés par les clients (atelier).

> **Implémenté (2026-11-10).** Feature `reparations` (dépend de `clients`, `stock`), **en option** pour l'Électronique : le propriétaire l'active dans « Paramètres du module ».
>
> - Tables `reparations` (n° `REP-…`, client enregistré ou nom/téléphone libres, appareil, marque, modèle, IMEI/série, panne, état à la réception, diagnostic, technicien = employé, devis, prix final, délai), `pieces_reparation`, `paiements_reparation`, `suivi_reparations`.
> - Statuts `recu` → `diagnostic` → `attente_pieces` → `en_reparation` → `repare` → `livre` (ou `annule`). Livrer exige d'avoir tout payé (`PAIEMENT_INCOMPLET`) ; une réparation payée ne s'annule pas (`REPARATION_PAYEE`) ; close, plus de modification (`REPARATION_CLOSE`).
> - Pièces : sortie de stock `reparation` (retirée ou annulée : retour en stock). Paiements : espèces en caisse, Mobile Money/carte/virement hors caisse ; jamais plus que le reste (`PAIEMENT_INVALIDE`).
> - Routes `/reparations` (index `en_cours`, `statut`, `recherche`, `client_id`, `technicien_id`), `/{id}`, `/{id}/statut`, `/{id}/pieces`, `/{id}/paiements`. Permissions `reparations.voir` / `reparations.gerer`.

> **Atelier (2026-11-16).** Le domaine Atelier inclut dossiers de travaux (`reparations`), `devis` (nouvelle feature, dépend de `clients`), produits, stock, catégories, fournisseurs, dépenses, équipements et rapport de gestion ; la fabrication (`preparations`) y est en option (migration `add_workshop_features`, idempotente).
>
> - **Spécialité** : `PUT /boutiques/{id}/parametres/specialite` (`mecanique`, `moto`, `menuiserie`, `couture`, `soudure`, `electronique`, `informatique`, `tapisserie`, `plomberie`, `maintenance`, `fabrication`, `autre`), stockée dans `boutiques.parametres` : l'application adapte les champs du bien confié.
> - **Dossier** : `bien` (JSON libre : immatriculation, kilométrage, dimensions, tissu…), `photos` (`POST/DELETE /reparations/{id}/photos[/{index}]`, 10 max), `techniciens` (pivot `techniciens_reparation`, le premier devient principal), lignes facturées `prestations_reparation` (`POST/DELETE /reparations/{id}/prestations` : prestation du catalogue au prix ajustable, main-d'œuvre, matériau, frais, remise négative). Dû = prix final, sinon lignes facturées (+ pièces `facturee`), sinon estimation. Pièce `facturee=false` : coût suivi, non refacturé (dossier issu d'un devis). Statut `attente_validation` (accord du client). `en_retard` (date prévue dépassée), filtres `en_retard`, `technicien_id` (principal ou équipe), `GET /reparations/resume`.
> - **Livraison** distincte du paiement : un reste dû bloque (`PAIEMENT_INCOMPLET`) sauf `laisser_a_credit` (client enregistré, `CLIENT_REQUIS`) — il passe au compte client (`montant_credit`) ; `livre_par_id`, `remis_a`, `reception_confirmee`.
> - **Devis** (`devis`, `lignes_devis`, `QuoteService`) : `GET/POST /devis`, `GET/PUT /devis/{id}`, `POST /devis/{id}/statut` (`brouillon`, `envoye`, `accepte`, `refuse`), `POST /devis/{id}/transformer`. Total = Σ lignes − remise ; `expire` calculé (validité dépassée, `DEVIS_EXPIRE`) ; accepté, refusé ou transformé : figé (`DEVIS_FIGE`). La transformation crée le dossier avec client, objet, description et toutes les lignes (remise → ligne négative). Droits : `reparations.voir` / `reparations.gerer`.
> - Services : `prix_variable` (« à partir de »). Unités `metre`, `m2`, `rouleau`. Dépenses : `carburant`, `consommables`. Équipements : compresseur, poste à souder, machine à coudre, perceuse, scie électrique, outil de diagnostic, pont élévateur, ordinateur.
> - Rapport de gestion, section `reparations` : créés, en cours, en retard, prêts, livrés, annulés, durée moyenne, prestations et main-d'œuvre facturées, encaissé, coût des pièces, par technicien, devis émis/acceptés/refusés.

> **Boutique de vêtements (2026-11-17).** Nouvelles features `variantes`, `collections`, `promotions` ; le domaine Vêtements inclut aussi fournisseurs, retours, marques et modèles, dépenses et rapport de gestion (migration `add_clothing_features`, idempotente).
>
> - **Variantes** : une variante est un produit rattaché à son article (`produits.produit_parent_id`, `taille`, `couleur`) — stock, historique, prix, SKU, code-barres, achats, ventes, retours, transferts et inventaires fonctionnent par variante sans autre changement. `POST /produits/{id}/variantes` (`tailles`, `couleurs` : combinaisons manquantes créées, héritant prix et fiche ; existantes conservées), `GET /produits/{id}/variantes` (grille et stock). Renommer l'article renomme ses variantes. Filtres produits : `sans_variantes`, `stockables`, `parent_id`, `taille`, `couleur`, `public_cible`, `collection_id` ; `recherche` couvre nom, SKU et code-barres exact.
> - Produit : `public_cible` (homme, femme, enfant, bébé, mixte), `collection_id`, états `friperie` et `destockage`. Catégories : `parent_id` (sous-catégories ; filtrer une catégorie inclut ses sous-catégories). Collections : `GET/POST/PUT /collections`. Clients : `preferences` (taille, pointure, couleurs).
> - **Promotions** (`promotions`, `produits_promotion`) : `GET/POST /promotions`, `GET/PUT /promotions/{id}` (statistiques de ventes). % ou montant par article, datées, portée toute la boutique / articles (les variantes héritent) / catégorie (et sous-catégories) / collection. `SaleService` applique la meilleure promotion en cours sauf remise manuelle plus forte ; `lignes_vente.promotion_id`. La liste des produits expose `promotion` (prix promotionnel). Droits : lecture `ventes.voir`, écriture `produits.modifier`.
> - **Échange** `POST /retours/echange` : retour (décision `echange`, lié à la vente d'origine) puis vente des nouveaux articles (`retours.vente_echange_id`) où la valeur rendue compte comme déjà réglée ; le client paie la différence (moyen au choix) ; si le nouvel article coûte moins, l'écart (`montant_rendu`) est rendu en espèces ou en avoir. Droits : `ventes.annuler` et `ventes.creer`.
> - Rapport de gestion : section `mode` (ventes par taille, couleur, collection ; variantes épuisées ; invendus depuis 60 jours) et `retours` (nombre, échanges, articles, remboursé, avoirs, défectueux, plus retournés). Dépenses : `publicite`.

> **Pharmacie (2026-11-18).** Nouvelles features `lots_stricts`, `conditionnement`, `commandes_fournisseurs`, `ordonnances` ; le domaine Pharmacie inclut aussi `conservation`, retours, dépenses, rapport de gestion et promotions (migration `add_pharmacy_features`, idempotente ; la boucherie garde ses lots sans contrôle strict).
>
> - **Produit** : `dci`, `forme`, `dosage`, `presentation`, `laboratoire`, `sur_ordonnance`, `unites_par_boite` (stock en unités ; vente « gros » = boîte = N unités, `Product::stockQuantityFor`, aussi pour les retours). La recherche couvre la DCI.
> - **Lots** (`lots_stock`) : `numero_lot` (fabricant), `fabrique_le`, `prix_achat`, `fournisseur_id`, `achat_id` repris de la ligne d'achat (`lignes_achat.numero_lot`, `date_peremption`, `fabrique_le`) ; `statut` `actif`/`bloque`/`rappele` (`POST /lots/{id}/statut`, motif exigé) ; journal append-only `journal_lots` (création, modification, blocage, rappel, retour). `GET /lots/{id}` (ventes du lot, journal), `GET /lots/peremptions?seuil=` (tableau, valeur au prix d'achat), `GET /lots/alertes` (par seuil, bloqués, rappelés), `PUT /parametres/peremption` (seuils dans `boutiques.parametres`).
> - **Vente** (`lots_stricts`) : `lignes[].lot_id` facultatif ; sinon premier périmé, premier sorti parmi les lots vendables. Lot périmé, bloqué ou rappelé, ou quantité vendable insuffisante : `LOT_NON_VENDABLE`. Lots sortis enregistrés dans `lots_vente` (exposés sur la ligne) ; l'annulation les reconstitue.
> - **Achats** (`commandes_fournisseurs`) : `commande=true` → `achats.statut=commande` (ni stock, ni dette, ni règlement) ; `POST /achats/{id}/reception` (quantités reçues, lots, péremptions, facture) → stock, lots, dette, coût de revient. `remise_fournisseur` répartie comme les frais (négative).
> - **Ordonnances** (Customers : `ordonnances`, `lignes_ordonnance`, `delivrances_ordonnance`) : CRUD, photo sur disque privé, annulation. Vente avec `ordonnance_id` : un produit `sur_ordonnance` exige une ordonnance qui le prescrit avec un reste suffisant (`ORDONNANCE_REQUISE`, `ORDONNANCE_INSUFFISANTE`, `ORDONNANCE_CLOSE`) ; aucune substitution ; délivrances partielles ; l'annulation de la vente les annule. Permissions `ordonnances.voir` (propriétaire, administrateur, gérant, caissier) et `ordonnances.gerer` (propriétaire, administrateur, gérant).
> - **Retours** : `etat_produits=a_verifier` → quarantaine (hors stock), `POST /retours/{id}/verification` (`reintegrer` / `detruire`, `stock.ajuster`) ; tracé (qui, quand, note).
> - Rapport de gestion : sections `peremptions` et `approvisionnements`. Dépenses : `frais_administratifs`.

### Fonctionnalités en option (2026-11-10)
> L'admin classe chaque fonctionnalité d'un métier : **Non**, **En option** ou **Incluse** (`fonctionnalites_domaine.optionnelle`). Une option est désactivée par défaut ; le propriétaire (permission `membres.gerer`) l'active via `GET/PUT /boutiques/{id}/parametres/fonctionnalites` (`{fonctionnalites: {slug: bool}}`, `OPTION_INDISPONIBLE` hors options). Retirer l'option au métier retire aussi le choix des boutiques. Écran « Paramètres du module » dans l'application.


> **Domaine « Autre » configurable (2026-11-22).** Ventes, caisse et rapports toujours actifs ; produits, services, clients, catégories et dépenses actifs par défaut mais désactivables ; stock, fournisseurs, suivi des opérations (`commandes`), rapport de gestion et matériel activables (migration `configure_autre_domain_options`). Aucun module spécialisé n'est proposé.
>
> - `GET /parametres/fonctionnalites` renvoie aussi `inclus_abonnement` et `depend_de` ; activer une option absente de l'abonnement → `OPTION_NON_INCLUSE` (`FeatureGate::planIncludes`). Nouvelle dépendance globale : `stock` → `produits`.
> - Fiche activité : `PUT /parametres/activite` (`nom`, `description`, `secteur`, `offre` produits|services|les_deux, `unites[]`) dans `boutiques.parametres.activite` ; saisie après la création d'un établissement « Autre » (Paramètres du module). Vocabulaire générique du domaine.
> - Prestations : `unite_tarif` accepte aussi `heure`, `seance`, `jour`, `forfait` ; quantité décimale à la caisse pour heure, jour, kg, m². Suivi des opérations : libellés neutres (à faire, en cours, terminée, remise).
> - Rapport de gestion (tous métiers) : `ventes.facturation` — facturé, produits, prestations, forfaits, mis à crédit, remboursements de crédit reçus, encaissé, reste dû des clients, ventes annulées.

### Reports
Lecture seule : agrège les données des autres modules (ventes par période, marges, rotation de stock, présence employé) et gère d'éventuels `ReportPreset` sauvegardés par un utilisateur. Ne possède pas de données métier primaires — dépend en lecture de tous les autres modules, jamais l'inverse.

> **Rapport de gestion (2026-11-03).** Feature `rapports_gestion` (dépend de `rapports`), défaut du seul domaine Boucherie ; le tableau de bord ne change pas. `GET /rapports/gestion?periode=` (`aujourdhui`, `cette_semaine` — nouvelle période, du lundi, aussi acceptée par le tableau de bord —, `7_jours`, `30_jours`, `ce_mois`). `ManagementReportService`, lecture seule, chaque section seulement si sa feature est active : ventes (CA, poids vendu en kg, par catégorie, morceaux les plus vendus, coût des marchandises, marge commerciale — même règle que le bénéfice estimé du tableau de bord), stock (valeur au prix d'achat, poids, faibles/ruptures), achats de la période et dettes fournisseurs, créances clients, dépenses (+ salaires Employees), pertes de stock et de découpe. **Bénéfice net estimé = marge − dépenses − pertes de stock** ; la perte de découpe n'est pas retirée (déjà dans le coût de revient des morceaux). Helpers communs dans `Reports/Support/QueriesStoreData`.

> **Électronique (2026-11-12).** `rapports_gestion` et `depenses` inclus pour l'Électronique. Ajouts : marge par catégorie et par produit, `par_marque` (si `marques_modeles`), `par_vendeur`, appareils en stock / défectueux (si `appareils`), section `garanties` (actives, expirées, réclamations en cours), section `reparations` (en cours, livrées, encaissé, coût des pièces). **Bénéfice net = marge − dépenses − pertes + (encaissements des réparations − coût des pièces).** Stock : filtres `categorie_id`, `marque`, `modele`, `etat`. Dépenses : catégories `livraison` et `douane`.

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
