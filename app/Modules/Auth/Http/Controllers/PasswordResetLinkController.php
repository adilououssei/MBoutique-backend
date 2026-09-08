<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Requests\ForgotPasswordRequest;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController extends ApiController
{
    public function store(ForgotPasswordRequest $request)
    {
        // Always return success, whether or not the email exists — do not
        // let this endpoint be used to enumerate registered accounts.
        Password::sendResetLink($request->only('email'));

        return $this->success(null, 'Si un compte existe pour cet e-mail, un lien de réinitialisation a été envoyé.');
    }
}
