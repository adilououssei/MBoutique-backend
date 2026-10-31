<?php

namespace App\Modules\Transformations\Enums;

enum TransformationType: string
{
    /** Une carcasse (ou un quartier) découpée en morceaux commercialisables. */
    case Cutting = 'decoupe';

    /** Un produit préparé (haché, saucisses, brochettes…) à partir de matières premières. */
    case Preparation = 'preparation';

    /** La feature qui donne accès à ce type d'opération. */
    public function feature(): string
    {
        return match ($this) {
            self::Cutting => 'decoupe',
            self::Preparation => 'preparations',
        };
    }

    public function referencePrefix(): string
    {
        return match ($this) {
            self::Cutting => 'DEC',
            self::Preparation => 'PRE',
        };
    }
}
