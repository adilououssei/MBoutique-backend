<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'mot_de_passe' => ['required', 'string'],
            'nom_appareil' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Key used by the 'login' rate limiter — per email+IP, so a single
     * attacker can't lock out a legitimate user by spraying their email
     * from many IPs, nor brute-force one IP across many emails unchecked.
     */
    public function throttleKey(): string
    {
        return mb_strtolower((string) $this->input('email')).'|'.$this->ip();
    }
}
