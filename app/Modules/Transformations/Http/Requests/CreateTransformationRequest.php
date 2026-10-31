<?php

namespace App\Modules\Transformations\Http\Requests;

use App\Modules\Transformations\Enums\CostAllocation;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Découpe ou préparation : matières consommées (`entrees`), produits obtenus (`sorties`). */
class CreateTransformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $product = ['required', 'integer', 'distinct', TenantScopedRules::existsInCurrentStore('produits')->whereNull('deleted_at')];

        return [
            'entrees' => ['required', 'array', 'min:1', 'max:50'],
            'entrees.*.produit_id' => $product,
            'entrees.*.quantite' => ['required', 'numeric', 'gt:0'],
            // Coût saisi : total (carcasse payée d'un bloc) ou unitaire ; sinon le prix d'achat du produit.
            'entrees.*.cout_total' => ['nullable', 'numeric', 'min:0'],
            'entrees.*.cout_unitaire' => ['nullable', 'numeric', 'min:0'],
            'sorties' => ['required', 'array', 'min:1', 'max:100'],
            'sorties.*.produit_id' => $product,
            'sorties.*.quantite' => ['required', 'numeric', 'gt:0'],
            'methode_repartition' => ['nullable', Rule::enum(CostAllocation::class)],
            'frais_annexes' => ['nullable', 'numeric', 'min:0'],
            'fiche_preparation_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('fiches_preparation')->whereNull('deleted_at')],
            'consommer_avant' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'effectuee_le' => ['nullable', 'date', 'before_or_equal:now'],
            'mettre_a_jour_prix_achat' => ['sometimes', 'boolean'],
            'cle_idempotence' => ['nullable', 'string', 'max:100'],
        ];
    }
}
