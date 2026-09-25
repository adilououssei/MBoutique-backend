<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Enums\StockMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One endpoint, one Request, for every manually-recordable movement
 * (initial/purchase/return_in/adjustment_in/adjustment_out/loss/stocktake)
 * — see docs/inventory.md §"Contrat de validation" for why `quantity`
 * (an unsigned magnitude, direction implied by `type`) and
 * `counted_quantity` (an absolute physical count, only for stocktake)
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
            'quantity' => ['required_unless:type,stocktake', 'prohibited_if:type,stocktake', 'numeric', 'gt:0'],
            'counted_quantity' => ['required_if:type,stocktake', 'prohibited_unless:type,stocktake', 'numeric', 'min:0'],
            // Only meaningful the one time a Stock is created.
            'minimum_quantity' => ['nullable', 'numeric', 'min:0', 'prohibited_unless:type,initial'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function type(): StockMovementType
    {
        return StockMovementType::from($this->string('type')->value());
    }
}
