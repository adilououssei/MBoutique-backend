<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Requests\ResetPasswordRequest;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NewPasswordController extends ApiController
{
    public function store(ResetPasswordRequest $request)
    {
        // Laravel's password broker expects its own English credential
        // keys (email/token/password) — the French API fields are mapped
        // onto them here, the only place the broker is called.
        $status = Password::reset(
            [
                'email' => $request->validated('email'),
                'token' => $request->validated('jeton'),
                'password' => $request->validated('mot_de_passe'),
            ],
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->validated('mot_de_passe')),
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return $this->success(null, 'Mot de passe réinitialisé avec succès.');
    }
}
