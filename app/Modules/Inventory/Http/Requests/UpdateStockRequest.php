<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Only the low-stock threshold is ever editable directly — quantity is never touched here, only through a movement. */
class UpdateStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'minimum_quantity' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
