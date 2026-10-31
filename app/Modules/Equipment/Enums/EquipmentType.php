<?php

namespace App\Modules\Equipment\Enums;

enum EquipmentType: string
{
    case Freezer = 'congelateur';
    case ColdRoom = 'chambre_froide';
    case Fridge = 'refrigerateur';
    case Display = 'vitrine_refrigeree';
    case Scale = 'balance';
    case Saw = 'scie';
    case Mincer = 'hachoir';
    case Slicer = 'trancheuse';
    case Oven = 'four';
    case Stove = 'cuisiniere';
    case Fryer = 'friteuse';
    case Grill = 'grill';
    case CoffeeMachine = 'machine_cafe';
    case Blender = 'mixeur';
    case Compressor = 'compresseur';
    case Welder = 'poste_souder';
    case SewingMachine = 'machine_coudre';
    case Drill = 'perceuse';
    case PowerSaw = 'scie_electrique';
    case DiagnosticTool = 'outil_diagnostic';
    case Lift = 'pont_elevateur';
    case Computer = 'ordinateur';
    case SalonChair = 'fauteuil';
    case MassageTable = 'table_massage';
    case UvLamp = 'lampe_uv';
    case CareDevice = 'appareil_soin';
    case HairDryer = 'seche_cheveux';
    case Clipper = 'tondeuse';
    case Straightener = 'lisseur';
    case HoodDryer = 'casque_chauffant';
    case WashBasin = 'bac_lavage';
    case WashingMachine = 'lave_linge';
    case Dryer = 'seche_linge';
    case DryCleaner = 'machine_nettoyage_sec';
    case IroningTable = 'table_repassage';
    case SteamPress = 'presse_vapeur';
    case Other = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::Freezer => 'Congélateur',
            self::ColdRoom => 'Chambre froide',
            self::Fridge => 'Réfrigérateur',
            self::Display => 'Vitrine réfrigérée',
            self::Scale => 'Balance',
            self::Saw => 'Scie à os',
            self::Mincer => 'Hachoir',
            self::Slicer => 'Trancheuse',
            self::Oven => 'Four',
            self::Stove => 'Cuisinière',
            self::Fryer => 'Friteuse',
            self::Grill => 'Grill / barbecue',
            self::CoffeeMachine => 'Machine à café',
            self::Blender => 'Mixeur / blender',
            self::Compressor => 'Compresseur',
            self::Welder => 'Poste à souder',
            self::SewingMachine => 'Machine à coudre',
            self::Drill => 'Perceuse',
            self::PowerSaw => 'Scie électrique',
            self::DiagnosticTool => 'Outil de diagnostic',
            self::Lift => 'Pont élévateur',
            self::Computer => 'Ordinateur / équipement informatique',
            self::SalonChair => 'Fauteuil de coiffure / soin',
            self::MassageTable => 'Table de massage',
            self::UvLamp => 'Lampe UV / LED (ongles)',
            self::CareDevice => 'Appareil de soin (visage, épilation…)',
            self::HairDryer => 'Sèche-cheveux',
            self::Clipper => 'Tondeuse',
            self::Straightener => 'Lisseur / fer à boucler',
            self::HoodDryer => 'Casque chauffant',
            self::WashBasin => 'Bac de lavage',
            self::WashingMachine => 'Lave-linge',
            self::Dryer => 'Sèche-linge',
            self::DryCleaner => 'Machine de nettoyage à sec',
            self::IroningTable => 'Table / fer à repasser',
            self::SteamPress => 'Presse vapeur',
            self::Other => 'Autre',
        };
    }

    /** Espace de conservation : peut recevoir des lots de stock. */
    public function isStorage(): bool
    {
        return in_array($this, [self::Freezer, self::ColdRoom, self::Fridge, self::Display], true);
    }

    /** @return list<string> */
    public static function storageValues(): array
    {
        return array_values(array_map(fn (self $t) => $t->value, array_filter(self::cases(), fn (self $t) => $t->isStorage())));
    }
}
