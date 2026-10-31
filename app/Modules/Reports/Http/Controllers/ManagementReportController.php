<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Modules\Reports\Http\Requests\DashboardReportRequest;
use App\Modules\Reports\Services\ManagementReportService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;

/** GET /rapports/gestion?periode= — même validation de période que le tableau de bord. */
class ManagementReportController extends ApiController
{
    public function __construct(private readonly ManagementReportService $reports) {}

    public function show(Store $store, DashboardReportRequest $request): JsonResponse
    {
        return $this->success($this->reports->build($store, $request->period(), null, $request->customRange()));
    }
}
