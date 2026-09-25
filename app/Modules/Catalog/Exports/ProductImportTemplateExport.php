<?php

namespace App\Modules\Catalog\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The downloadable starting point for a bulk import — see
 * docs/catalog.md §"Import Excel". Column order/names here are the
 * contract ProductImportService reads back (via WithHeadingRow on
 * ProductsImport), so the two must stay in sync.
 */
class ProductImportTemplateExport implements Export, FromArray, WithHeadings
{
    public function headings(): array
    {
        return [
            'name', 'category', 'description', 'sku', 'barcode', 'unit',
            'purchase_price', 'retail_enabled', 'retail_price',
            'wholesale_enabled', 'wholesale_price', 'is_active',
        ];
    }

    public function array(): array
    {
        return [
            [
                'Coca-Cola 50cl', 'Boissons', 'Bouteille en verre 50cl', 'CC001', '123456789', 'piece',
                300, 'true', 500,
                'true', 450, 'true',
            ],
        ];
    }
}
