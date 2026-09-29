<?php

namespace App\Modules\Tenancy\Enums;

enum StoreUserStatus: string
{
    case Invited = 'invite';
    case Active = 'actif';
    case Revoked = 'revoque';
}
