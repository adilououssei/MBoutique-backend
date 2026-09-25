<?php

namespace App\Modules\Catalog\Imports;

use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Pure sheet-reading concern — no business logic here on purpose. It's
 * handed to Excel::toCollection() so ProductImportService gets back
 * rows keyed by the template's column names (name, category, sku, ...);
 * everything downstream (category resolution, validation, creation)
 * goes through the exact same ProductRules/ProductService as manual
 * creation — see docs/catalog.md §"Import Excel".
 */
class ProductsImport implements Import, SkipsEmptyRows, WithHeadingRow {}
