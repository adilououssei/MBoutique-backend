<?php

namespace App\Modules\Features\Support;

/**
 * Catalogue de référence des fonctionnalités et des métiers : classement
 * (à quel type d'activité une fonctionnalité est destinée), description, et
 * vocabulaire d'exemple de chaque métier (placeholders de l'application).
 * Sert à initialiser les données (migration, FeatureSeeder) ; ensuite tout
 * reste modifiable par l'admin plateforme — c'est la base qui fait foi.
 */
final class FeatureCatalog
{
    /** Catégories, dans l'ordre d'affichage. */
    public const CATEGORIES = [
        'socle' => 'Commun à tous les métiers',
        'commerce' => 'Vente de produits',
        'services' => 'Prestations de services',
        'restauration' => 'Restauration et commandes',
        'boucherie' => 'Boucherie',
        'gestion' => 'Gestion',
        'autres' => 'Autres',
    ];

    /** @var array<string, array{0: string, 1: string}> slug => [catégorie, description] */
    public const FEATURES = [
        'ventes' => ['socle', 'Encaisser des ventes, reçus, annulations.'],
        'caisse' => ['socle', 'Caisses, sessions, entrées et sorties d’argent.'],
        'clients' => ['socle', 'Carnet de clients, ventes à crédit et remboursements.'],
        'employes' => ['socle', 'Personnel, salaires, avances et primes.'],
        'rapports' => ['socle', 'Tableau de bord : chiffre d’affaires, ventes, bénéfice estimé.'],
        'produits' => ['commerce', 'Catalogue de produits, prix de détail et de gros.'],
        'categories' => ['commerce', 'Classement du catalogue par catégories.'],
        'stock' => ['commerce', 'Quantités, seuils d’alerte, mouvements, inventaires, transferts.'],
        'fournisseurs' => ['commerce', 'Fournisseurs, achats, règlements et dettes.'],
        'creation_vocale' => ['commerce', 'Créer un produit en le dictant.'],
        'import_produits' => ['commerce', 'Importer le catalogue depuis un fichier Excel.'],
        'marques_modeles' => ['commerce', 'Marque, modèle, état et fiche technique des produits.'],
        'appareils' => ['commerce', 'Suivi des appareils à l’unité par IMEI ou numéro de série.'],
        'variantes' => ['commerce', 'Tailles et couleurs : variantes d’un article, stock, prix et code-barres par variante.'],
        'collections' => ['commerce', 'Collections et saisons : articles regroupés, ventes et invendus par collection.'],
        'promotions' => ['commerce', 'Promotions et soldes datés, en pourcentage ou en montant, appliqués automatiquement à la vente.'],
        'lots_stricts' => ['commerce', 'Lots contrôlés : vente refusée sur un lot périmé, bloqué ou rappelé ; lot enregistré sur chaque vente.'],
        'conditionnement' => ['commerce', 'Vente à la boîte ou à l’unité : conversion explicite du stock (unités par boîte).'],
        'commandes_fournisseurs' => ['commerce', 'Commande fournisseur, puis réception vérifiée (quantités, lots, péremptions) avant l’entrée en stock.'],
        'ordonnances' => ['commerce', 'Ordonnances : prescripteur, médicaments prescrits, délivrances partielles liées aux ventes.'],
        'retours' => ['commerce', 'Retour d’une partie d’une vente : remboursement, avoir ou échange.'],
        'garanties' => ['commerce', 'Garanties créées à la vente, recherche par IMEI ou facture, réclamations.'],
        'services' => ['services', 'Prestations vendues (coupe, réparation, lavage…).'],
        'rendez_vous' => ['services', 'Agenda et réservations par employé.'],
        'consommables' => ['services', 'Produits consommés par prestation : déduits du stock à l’encaissement, coût des consommables.'],
        'forfaits' => ['services', 'Forfaits et abonnements : séances prépayées, validité, séances restantes par client.'],
        'fidelite' => ['services', 'Points de fidélité gagnés selon le montant dépensé, convertis en remise.'],
        'commissions' => ['services', 'Commissions des employés par prestation, calculées à l’encaissement, payées par la paie.'],
        'reparations' => ['services', 'Dossiers de travaux : bien confié, diagnostic, prestations, matériaux, techniciens, suivi, acompte, livraison.'],
        'devis' => ['services', 'Devis : prestations, matériaux, main-d’œuvre, frais, validité, document à partager, transformation en dossier.'],
        'articles_depot' => ['services', 'Pressing : chaque pièce déposée décrite (type, couleur, état, défauts), codée, suivie et remise individuellement ; paiement et remise séparés.'],
        'commandes' => ['restauration', 'Commandes sur place, à emporter, livraison, dépôt, avec acompte.'],
        'tables' => ['restauration', 'Plan de salle et tables d’un restaurant.'],
        'supplements' => ['restauration', 'Suppléments payants proposés avec un plat (œuf, frites, sauce…).'],
        'recettes' => ['restauration', 'Recette de chaque plat : ingrédients déduits du stock à la vente, coût de revient et marge.'],
        'cuisine' => ['restauration', 'Écran cuisine : plats à préparer, statut par plat, ticket de préparation.'],
        'reservations' => ['restauration', 'Réservations de tables enregistrées par le personnel.'],
        'decoupe' => ['boucherie', 'Découpe des carcasses : morceaux, pertes, coût de revient.'],
        'preparations' => ['boucherie', 'Fiches et production : produits préparés (boucherie) ou fabriqués (atelier), coût de revient.'],
        'conservation' => ['boucherie', 'Lots, dates limites, alertes de péremption, pertes valorisées.'],
        'rapports_gestion' => ['gestion', 'Rapport de gestion : marges, stock, dettes, dépenses, pertes, bénéfice net.'],
        'depenses' => ['gestion', 'Dépenses d’exploitation par catégorie (électricité, loyer…).'],
        'equipements' => ['gestion', 'Équipements, entretiens, pannes et lieux de stockage.'],
    ];

    /** Champs du vocabulaire d'un métier (clé => libellé dans l'admin). */
    public const VOCABULARY_FIELDS = [
        'produit' => 'Exemple de produit',
        'categorie' => 'Exemple de catégorie',
        'service' => 'Exemple de prestation',
        'fournisseur' => 'Exemple de fournisseur',
        'poste' => 'Exemples de postes',
        'note_rendez_vous' => 'Exemple de note de rendez-vous',
        'dictee' => 'Exemple de dictée vocale',
        'unite' => 'Unité de vente par défaut',
    ];

    /** @var array<string, array<string, string>> */
    public const VOCABULARY = [
        'alimentation_generale' => ['produit' => 'Riz parfumé 25 kg', 'categorie' => 'Boissons, Épicerie, Hygiène', 'fournisseur' => 'Grossiste Diallo & Fils', 'poste' => 'Vendeur, Caissier, Magasinier', 'dictee' => 'Riz parfumé 25 kg en détail à 18000 et en gros à 17000', 'unite' => 'piece'],
        'boucherie' => ['produit' => 'Filet de bœuf', 'categorie' => 'Bœuf, Mouton, Volaille', 'fournisseur' => 'Éleveur Ba, Abattoir municipal', 'poste' => 'Boucher, Caissier, Livreur', 'dictee' => 'Filet de bœuf au kilo à 7000', 'unite' => 'kg'],
        'restaurant' => ['produit' => 'Poulet braisé', 'categorie' => 'Plats, Boissons, Desserts', 'fournisseur' => 'Marché central, Grossiste boissons', 'poste' => 'Serveur, Cuisinier, Caissier', 'dictee' => 'Poulet braisé à 3500', 'unite' => 'piece'],
        'coiffure' => ['produit' => 'Gel coiffant', 'categorie' => 'Soins, Accessoires', 'service' => 'Coupe homme', 'fournisseur' => 'Distributeur cosmétiques', 'poste' => 'Coiffeur, Coiffeuse, Accueil', 'note_rendez_vous' => 'tresses longues, apporter les mèches', 'dictee' => 'Gel coiffant à 1500', 'unite' => 'piece'],
        'salon_beaute' => ['produit' => 'Crème hydratante', 'categorie' => 'Soins visage, Ongles', 'service' => 'Manucure', 'fournisseur' => 'Distributeur cosmétiques', 'poste' => 'Esthéticienne, Accueil', 'note_rendez_vous' => 'pose de vernis semi-permanent', 'dictee' => 'Crème hydratante à 4000', 'unite' => 'piece'],
        'pharmacie' => ['produit' => 'Paracétamol 500 mg, boîte de 16', 'categorie' => 'Antalgiques, Parapharmacie', 'fournisseur' => 'Grossiste répartiteur', 'poste' => 'Pharmacien, Vendeur', 'dictee' => 'Paracétamol 500 mg à 800', 'unite' => 'boite'],
        'vetements' => ['produit' => 'Chemise en wax, taille M', 'categorie' => 'Hommes, Femmes, Enfants', 'fournisseur' => 'Atelier de couture Sow', 'poste' => 'Vendeuse, Retoucheur', 'dictee' => 'Chemise en wax à 12000', 'unite' => 'piece'],
        'electronique' => ['produit' => 'Chargeur USB-C 20 W', 'categorie' => 'Téléphones, Accessoires', 'fournisseur' => 'Importateur Electro Plus', 'poste' => 'Vendeur, Technicien', 'dictee' => 'Chargeur USB-C à 5000', 'unite' => 'piece'],
        'atelier' => ['produit' => 'Plaquettes de frein', 'categorie' => 'Pièces, Consommables', 'service' => 'Réparation de moteur', 'fournisseur' => 'Magasin de pièces détachées', 'poste' => 'Mécanicien, Apprenti', 'dictee' => 'Plaquettes de frein à 9000', 'unite' => 'piece'],
        'autre' => ['produit' => 'Article', 'categorie' => 'Produits, Prestations', 'service' => 'Prestation à l’heure', 'fournisseur' => 'Fournisseur principal', 'poste' => 'Responsable, Employé', 'note_rendez_vous' => 'précisions sur la demande', 'dictee' => 'Article à 1000', 'unite' => 'piece'],
        'pressing' => ['service' => 'Lavage et repassage de costume', 'categorie' => 'Vêtements, Linge de maison', 'fournisseur' => 'Fournisseur de lessive', 'poste' => 'Repasseur, Accueil', 'unite' => 'piece'],
    ];

    public static function categoryOf(?string $category): string
    {
        return self::CATEGORIES[$category ?? 'autres'] ?? self::CATEGORIES['autres'];
    }
}
