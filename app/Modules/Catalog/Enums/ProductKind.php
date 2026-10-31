<?php

namespace App\Modules\Catalog\Enums;

/** Nature d'un article (restaurant) : distingue la carte des matières premières. */
enum ProductKind: string
{
    case Dish = 'plat';
    case Drink = 'boisson';
    /** Matière première : achetée et consommée par les recettes, pas vendue à la carte. */
    case Ingredient = 'ingredient';
    /** Menu à prix fixe : sa recette liste les plats qui le composent. */
    case Combo = 'formule';
    case Other = 'autre';
}
