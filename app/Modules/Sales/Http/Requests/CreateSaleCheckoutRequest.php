<?php

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Catalog\Enums\PricingMode;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The cart isn't a persisted resource in this phase (see docs/sales.md
 * §"Panier") — the frontend holds it locally and submits the whole
 * thing here. Tenant-scoped existence for every referenced id
 * (product/customer/cash_register) is enforced here, at the validation
 * layer, same as everywhere else in the project — never trusted from
 * an `exists:table,id` alone.
 */
class CreateSaleCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('produits')],
            'lignes.*.mode_prix' => ['required', Rule::enum(PricingMode::class)],
            'lignes.*.quantite' => ['required', 'numeric', 'gt:0'],
            'caisse_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
            'client_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('clients')],
            // Only cash is accepted in this phase — see PaymentMethod::acceptedForCheckout().
            'mode_paiement' => ['sometimes', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::acceptedForCheckout()))],
            'montant_remise' => ['nullable', 'numeric', 'min:0'],
            'cle_idempotence' => ['nullable', 'string', 'max:100'],
        ];
    }
}
