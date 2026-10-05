<?php

namespace App\Modules\Orders\Enums;

enum OrderType: string
{
    /** Restaurant : servi à une table. */
    case DineIn = 'sur_place';

    case Takeaway = 'a_emporter';

    case Delivery = 'livraison';

    /** Atelier/pressing : le client dépose un article et revient le chercher. */
    case DropOff = 'depot';
}
