<?php

namespace App\Modules\Reports\Http\Requests;

use App\Modules\Reports\Enums\ReportPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorization is handled by the `permission:rapports.voir` route
 * middleware — this request only validates the period.
 */
class DashboardReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'periode' => ['sometimes', Rule::enum(ReportPeriod::class)],
        ];
    }

    public function period(): ReportPeriod
    {
        return ReportPeriod::tryFrom((string) $this->validated('periode')) ?? ReportPeriod::Today;
    }
}
