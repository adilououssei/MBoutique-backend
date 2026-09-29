<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Enums\StockMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One endpoint, one Request, for every manually-recordable movement
 * (initial/achat/retour/ajustement_entree/ajustement_sortie/perte/inventaire)
 * — see docs/inventory.md §"Contrat de validation" for why `quantite`
 * (an unsigned magnitude, direction implied by `type`) and
 * `quantite_comptee` (an absolute physical count, only for stocktake)
 * are two distinct fields rather than one ambiguous "quantity or delta".
 */
class CreateStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $recordable = array_map(fn (StockMovementType $t) => $t->value, StockMovementType::manuallyRecordable());

        return [
            'type' => ['required', Rule::in($recordable)],
            'quantite' => ['required_unless:type,inventaire', 'prohibited_if:type,inventaire', 'numeric', 'gt:0'],
            'quantite_comptee' => ['required_if:type,inventaire', 'prohibited_unless:type,inventaire', 'numeric', 'min:0'],
            // Only meaningful the one time a Stock is created.
            'quantite_minimum' => ['nullable', 'numeric', 'min:0', 'prohibited_unless:type,initial'],
            'motif' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function type(): StockMovementType
    {
        return StockMovementType::from($this->string('type')->value());
    }
}
