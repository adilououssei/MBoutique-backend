<?php

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Catalog\Enums\PricingMode;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // Une ligne = un produit (avec mode de prix) OU un service — la
            // règle « exactement l'un des deux » est dans withValidator().
            'lignes.*.produit_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('produits')],
            'lignes.*.service_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('services')],
            'lignes.*.mode_prix' => ['nullable', Rule::enum(PricingMode::class)],
            'lignes.*.quantite' => ['required', 'numeric', 'gt:0'],
            // Remise de ligne : montant (pas un pourcentage), plafonnée au
            // montant brut de la ligne par SaleService.
            'lignes.*.remise' => ['nullable', 'numeric', 'min:0'],
            'caisse_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
            'client_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('clients')],
            // Only cash is accepted in this phase — see PaymentMethod::acceptedForCheckout().
            'mode_paiement' => ['sometimes', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::acceptedForCheckout()))],
            'montant_remise' => ['nullable', 'numeric', 'min:0'],
            'cle_idempotence' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('lignes', []) as $i => $line) {
                if (! is_array($line)) {
                    continue;
                }
                $hasProduct = filled($line['produit_id'] ?? null);
                $hasService = filled($line['service_id'] ?? null);

                if ($hasProduct === $hasService) {
                    $validator->errors()->add("lignes.{$i}", 'Chaque ligne doit porter un produit ou un service, et un seul.');
                } elseif ($hasProduct && blank($line['mode_prix'] ?? null)) {
                    $validator->errors()->add("lignes.{$i}.mode_prix", 'Le mode de prix (détail ou gros) est obligatoire pour un produit.');
                }
            }
        });
    }
}
