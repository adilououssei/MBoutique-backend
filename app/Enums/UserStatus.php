<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'actif';
    case Inactive = 'inactif';
}
