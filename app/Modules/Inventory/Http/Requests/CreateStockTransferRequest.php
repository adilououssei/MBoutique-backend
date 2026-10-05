<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La destination n'est pas validée ici (autre boutique, donc hors du
 * contexte courant) : StockTransferService vérifie entreprise, suivi de
 * stock et droits, et répond TRANSFERT_INVALIDE.
 */
class CreateStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'boutique_destination_id' => ['required', 'integer'],
            'lignes' => ['required', 'array', 'min:1', 'max:200'],
            'lignes.*.produit_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('produits')],
            'lignes.*.quantite' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
