<?php

namespace App\Modules\Transformations\Enums;

enum TransformationStatus: string
{
    case Completed = 'terminee';
    case Cancelled = 'annulee';
}
