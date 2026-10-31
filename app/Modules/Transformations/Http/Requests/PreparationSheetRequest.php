<?php

namespace App\Modules\Transformations\Http\Requests;

use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/** Fiche de préparation : produit fini, rendement d'une fournée, ingrédients. */
class PreparationSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $product = TenantScopedRules::existsInCurrentStore('produits')->whereNull('deleted_at');

        return [
            'produit_id' => [$creating ? 'required' : 'sometimes', 'integer', $product],
            'nom' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'quantite_produite' => [$creating ? 'required' : 'sometimes', 'numeric', 'gt:0'],
            'duree_conservation_jours' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'actif' => ['sometimes', 'boolean'],
            'ingredients' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:50'],
            'ingredients.*.produit_id' => ['required', 'integer', 'distinct', 'different:produit_id', $product],
            'ingredients.*.quantite' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return ['ingredients.*.produit_id.different' => 'Le produit fini ne peut pas être son propre ingrédient.'];
    }
}
