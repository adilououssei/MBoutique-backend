<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Users\Http\Resources\UserResource;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;

class CurrentUserController extends ApiController
{
    public function show(Request $request)
    {
        return $this->success(new UserResource($request->user()));
    }
}
