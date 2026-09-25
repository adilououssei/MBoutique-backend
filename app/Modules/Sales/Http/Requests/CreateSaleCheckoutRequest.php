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
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('products')],
            'items.*.pricing_mode' => ['required', Rule::enum(PricingMode::class)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'cash_register_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('cash_registers')],
            'customer_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('customers')],
            // Only cash is accepted in this phase — see PaymentMethod::acceptedForCheckout().
            'payment_method' => ['sometimes', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::acceptedForCheckout()))],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }
}
