<?php

namespace App\Modules\Equipment\Enums;

enum EquipmentStatus: string
{
    case InService = 'en_service';
    case Broken = 'en_panne';
    case OutOfService = 'hors_service';
}
