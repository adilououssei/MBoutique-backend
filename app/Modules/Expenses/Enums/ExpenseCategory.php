<?php

namespace App\Modules\Expenses\Enums;

/** Catégories de dépenses d'exploitation (hors achats de marchandise, suivis par Suppliers). */
enum ExpenseCategory: string
{
    case Electricity = 'electricite';
    case Water = 'eau';
    case Ice = 'glace';
    case Gas = 'gaz';
    case Charcoal = 'charbon';
    case CleaningProducts = 'produits_entretien';
    case Communication = 'communication';
    case Internet = 'internet';
    case Fuel = 'carburant';
    case Supplies = 'consommables';
    case Advertising = 'publicite';
    case Administrative = 'frais_administratifs';
    case Transport = 'transport';
    case Delivery = 'livraison';
    case Customs = 'douane';
    case Packaging = 'emballages';
    case Equipment = 'equipements';
    case Furniture = 'mobilier';
    case Maintenance = 'entretien';
    case Salaries = 'salaires';
    case Rent = 'loyer';
    case Taxes = 'taxes';
    case Other = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::Electricity => 'Électricité',
            self::Water => 'Eau',
            self::Ice => 'Glace',
            self::Gas => 'Gaz',
            self::Charcoal => 'Charbon',
            self::CleaningProducts => 'Produits d’entretien',
            self::Communication => 'Communication (téléphone, internet)',
            self::Internet => 'Internet',
            self::Fuel => 'Carburant',
            self::Supplies => 'Consommables',
            self::Advertising => 'Publicité',
            self::Administrative => 'Frais administratifs',
            self::Transport => 'Transport',
            self::Delivery => 'Livraison',
            self::Customs => 'Douane',
            self::Packaging => 'Emballages',
            self::Equipment => 'Achat d’équipement',
            self::Furniture => 'Mobilier',
            self::Maintenance => 'Entretien et réparations',
            self::Salaries => 'Salaires',
            self::Rent => 'Loyer',
            self::Taxes => 'Taxes et impôts',
            self::Other => 'Autre',
        };
    }
}
