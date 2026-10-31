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
            // Salon : forfait vendu, employé qui a réalisé la prestation (commissions).
            'lignes.*.forfait_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('forfaits_prestations')->whereNull('deleted_at')],
            'lignes.*.employe_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('employes')],
            // Salon : points de fidélité du client convertis en remise.
            'points_utilises' => ['nullable', 'integer', 'min:0'],
            'lignes.*.mode_prix' => ['nullable', Rule::enum(PricingMode::class)],
            'lignes.*.quantite' => ['required', 'numeric', 'gt:0'],
            // Remise de ligne : montant (pas un pourcentage), plafonnée au
            // montant brut de la ligne par SaleService.
            'lignes.*.remise' => ['nullable', 'numeric', 'min:0'],
            // Produit suivi à l'unité : les appareils précis vendus (un par unité).
            // Pharmacie : ordonnance délivrée par cette vente.
            'ordonnance_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('ordonnances')],
            // Pharmacie : lot choisi par le vendeur (sinon celui qui périme le plus tôt).
            'lignes.*.lot_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('lots_stock')],
            'lignes.*.appareil_ids' => ['nullable', 'array'],
            'lignes.*.appareil_ids.*' => ['integer', 'distinct'],
            'caisse_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
            // Vente à crédit : un client existant, ou un nouveau saisi à la volée (vérifié dans withValidator()).
            'client_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('clients')],
            'nouveau_client' => ['nullable', 'array'],
            'nouveau_client.nom' => ['required_with:nouveau_client', 'string', 'max:255'],
            'nouveau_client.telephone' => ['nullable', 'string', 'max:30'],
            // Espèces ou crédit — see PaymentMethod::acceptedForCheckout().
            'mode_paiement' => ['sometimes', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::acceptedForCheckout()))],
            'montant_remise' => ['nullable', 'numeric', 'min:0'],
            // Vente à crédit : part payée comptant maintenant (plafonnée au total par SaleService).
            'acompte' => ['nullable', 'numeric', 'min:0'],
            'cle_idempotence' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('mode_paiement') === PaymentMethod::Credit->value && blank($this->input('client_id')) && blank($this->input('nouveau_client.nom'))) {
                $validator->errors()->add('client_id', 'Une vente à crédit doit avoir un client : choisissez-le ou saisissez son nom.');
            }
            foreach ((array) $this->input('lignes', []) as $i => $line) {
                if (! is_array($line)) {
                    continue;
                }
                $hasProduct = filled($line['produit_id'] ?? null);
                $hasService = filled($line['service_id'] ?? null);
                $hasPackage = filled($line['forfait_id'] ?? null);

                if ((int) $hasProduct + (int) $hasService + (int) $hasPackage !== 1) {
                    $validator->errors()->add("lignes.{$i}", 'Chaque ligne doit porter un produit, un service ou un forfait, et un seul.');
                } elseif ($hasProduct && blank($line['mode_prix'] ?? null)) {
                    $validator->errors()->add("lignes.{$i}.mode_prix", 'Le mode de prix (détail ou gros) est obligatoire pour un produit.');
                }
            }
        });
    }
}
