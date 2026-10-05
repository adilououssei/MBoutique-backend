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
            $report = $this->imports->import($request->file('fichier'), $store, $request->user()?->id);
        } catch (ProductImportRejectedException $e) {
            return $this->error($e->getMessage(), [], 422, 'IMPORT_PRODUITS_REJETE');
        }

        return $this->success($report, "{$report['importes']} produit(s) importé(s), {$report['rejetes']} rejeté(s).");
    }

    public function template(Store $store)
    {
        $this->authorize('import', [Product::class, $store]);

        return Excel::download(new ProductImportTemplateExport, 'modele-import-produits.xlsx');
    }
}
