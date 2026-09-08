<?php

namespace App\Modules\Tenancy\Enums;

enum StoreUserStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Revoked = 'revoked';
}
