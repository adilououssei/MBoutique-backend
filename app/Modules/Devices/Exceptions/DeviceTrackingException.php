<?php

namespace App\Modules\Devices\Exceptions;

use RuntimeException;

/**
 * Erreur métier du suivi des appareils ; `errorCode` est le code renvoyé à
 * l'application (APPAREILS_REQUIS, APPAREIL_INDISPONIBLE, APPAREIL_EXISTANT…),
 * y compris quand l'erreur survient pendant une vente ou un achat.
 */
class DeviceTrackingException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}
