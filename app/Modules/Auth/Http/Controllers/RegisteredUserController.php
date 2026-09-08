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
        $user = User::create($request->safe()->only(['name', 'email', 'phone', 'password']));

        $token = $user->createToken($request->input('device_name', 'api'))->plainTextToken;

        return $this->success([
            'user' => new UserResource($user),
            'token' => $token,
        ], 'Compte créé avec succès.', [], 201);
    }
}
