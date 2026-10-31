<?php

namespace App\Modules\Transformations\Enums;

/**
 * Répartition du coût des matières sur les produits obtenus.
 *
 * - `valeur` (défaut) : au prorata de la valeur de vente de chaque produit
 *   (quantité × prix) — un filet porte plus de coût qu'un os à soupe, comme
 *   en boucherie.
 * - `poids` : au prorata du poids, chaque kilo obtenu coûte pareil.
 */
enum CostAllocation: string
{
    case Value = 'valeur';
    case Weight = 'poids';
}
