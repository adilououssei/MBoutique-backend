<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `mimes` checks the actual file content (via fileinfo), not
            // just the extension — docs/catalog.md §"Import Excel":
            // "ne jamais faire confiance uniquement à l'extension".
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ];
    }
}
