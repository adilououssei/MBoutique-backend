<?php

namespace App\Modules\Equipment\Enums;

enum InterventionType: string
{
    /** Entretien périodique (nettoyage, dégivrage, étalonnage de balance…). */
    case Maintenance = 'entretien';

    /** Panne constatée : l'équipement passe « en panne ». */
    case Breakdown = 'panne';

    /** Réparation : l'équipement repasse « en service ». */
    case Repair = 'reparation';
}
