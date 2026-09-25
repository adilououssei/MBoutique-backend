<?php

namespace App\Modules\Catalog\Exceptions;

use RuntimeException;

/**
 * The whole file is rejected before any row is processed — an
 * unreadable/corrupt workbook or a row count past the sane limit
 * (docs/catalog.md §"Import Excel": "ne jamais faire confiance
 * uniquement à l'extension"). Distinct from a per-row rejection, which
 * never throws — the rest of the file still gets a report.
 */
class ProductImportRejectedException extends RuntimeException {}
