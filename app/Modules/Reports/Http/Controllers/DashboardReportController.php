<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Modules\Reports\Http\Requests\DashboardReportRequest;
use App\Modules\Reports\Services\DashboardReportService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;

class DashboardReportController extends ApiController
{
    public function __construct(private readonly DashboardReportService $reports) {}

    public function show(Store $store, DashboardReportRequest $request): JsonResponse
    {
        return $this->success($this->reports->build($store, $request->period()));
    }
}
