<?php

namespace App\Modules\Transformations\Exceptions;

use RuntimeException;

/** Opération incohérente (produit à la fois consommé et obtenu, répartition impossible…) — docs/modules.md §Transformations. */
class InvalidTransformationException extends RuntimeException {}
