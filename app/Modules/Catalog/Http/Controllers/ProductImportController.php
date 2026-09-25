<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Exceptions\ProductImportRejectedException;
use App\Modules\Catalog\Exports\ProductImportTemplateExport;
use App\Modules\Catalog\Http\Requests\ImportProductsRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductImportService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Maatwebsite\Excel\Facades\Excel;

class ProductImportController extends ApiController
{
    public function __construct(private readonly ProductImportService $imports) {}

    public function store(Store $store, ImportProductsRequest $request)
    {
        $this->authorize('import', [Product::class, $store]);

        try {
            $report = $this->imports->import($request->file('file'), $store);
        } catch (ProductImportRejectedException $e) {
            return $this->error($e->getMessage(), [], 422, 'PRODUCT_IMPORT_REJECTED');
        }

        return $this->success($report, "{$report['imported']} produit(s) importé(s), {$report['rejected']} rejeté(s).");
    }

    public function template(Store $store)
    {
        $this->authorize('import', [Product::class, $store]);

        return Excel::download(new ProductImportTemplateExport, 'products-import-template.xlsx');
    }
}
