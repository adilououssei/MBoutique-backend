<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Http\Requests\RegisterUserRequest;
use App\Modules\Users\Http\Resources\UserResource;
use App\Shared\Http\Controllers\ApiController;

class RegisteredUserController extends ApiController
{
    public function store(RegisterUserRequest $request)
    {
        $user = User::create([
            ...$request->safe()->only(['nom', 'email', 'telephone']),
            'password' => $request->validated('mot_de_passe'),
        ]);

        $token = $user->createToken($request->input('nom_appareil', 'api'))->plainTextToken;

        return $this->success([
            'utilisateur' => new UserResource($user),
            'jeton' => $token,
        ], 'Compte créé avec succès.', [], 201);
    }
}
