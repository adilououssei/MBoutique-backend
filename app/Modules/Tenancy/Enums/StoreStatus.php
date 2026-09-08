<?php

namespace App\Modules\Tenancy\Enums;

enum StoreStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
