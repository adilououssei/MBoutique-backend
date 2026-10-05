<?php

namespace App\Modules\Sales\Http\Requests;

use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

class CancelSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `caisse_id` : caisse d'où sort le remboursement. Facultatif tant que la
     * session de la vente est encore ouverte ; obligatoire en pratique sinon
     * (SaleService::cancel() exige une session ouverte) — docs/sales.md §20.
     */
    public function rules(): array
    {
        return [
            'motif' => ['required', 'string', 'max:500'],
            'caisse_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
        ];
    }
}
