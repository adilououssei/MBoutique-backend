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
            // Période personnalisée : dates locales de la boutique, un an au plus.
            'du' => ['required_if:periode,personnalisee', 'nullable', 'date_format:Y-m-d'],
            'au' => ['required_if:periode,personnalisee', 'nullable', 'date_format:Y-m-d', 'after_or_equal:du', 'before_or_equal:today'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->filled('du') && $this->filled('au') && strtotime((string) $this->input('au')) - strtotime((string) $this->input('du')) > 366 * 86400) {
                $validator->errors()->add('au', 'La période personnalisée ne peut pas dépasser un an.');
            }
        });
    }

    /** @return array{0: string, 1: string}|null */
    public function customRange(): ?array
    {
        return $this->period() === ReportPeriod::Custom ? [(string) $this->validated('du'), (string) $this->validated('au')] : null;
    }

    public function period(): ReportPeriod
    {
        return ReportPeriod::tryFrom((string) $this->validated('periode')) ?? ReportPeriod::Today;
    }
}
