<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Requests\UpdatePasswordRequest;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordController extends ApiController
{
    public function update(UpdatePasswordRequest $request)
    {
        $user = $request->user();

        if (! Hash::check($request->validated('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Le mot de passe actuel est incorrect.'],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($request->validated('password')),
        ])->save();

        return $this->success(null, 'Mot de passe mis à jour.');
    }
}
