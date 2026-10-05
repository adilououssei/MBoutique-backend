<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddOrderItemsRequest extends FormRequest
{
    use ValidatesOrderLines;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->lineRules(true);
    }
}
