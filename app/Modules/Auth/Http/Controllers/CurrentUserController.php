<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Support\Countries;
use App\Modules\Users\Http\Resources\UserResource;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CurrentUserController extends ApiController
{
    public function show(Request $request)
    {
        return $this->success(new UserResource($request->user()));
    }

    /** PUT moi/pays — pour les comptes créés avant que le pays soit demandé, ou après un déménagement. */
    public function updateCountry(Request $request)
    {
        $data = $request->validate(['pays' => ['required', 'string', Rule::in(Countries::codes())]]);

        $request->user()->forceFill(['pays' => $data['pays']])->save();

        return $this->success(new UserResource($request->user()), 'Pays enregistré.');
    }

    /** GET pays — liste proposée à l'inscription (public). */
    public function countries()
    {
        return $this->success(collect(Countries::all())->map(fn (string $name, string $code) => ['code' => $code, 'nom' => $name])->values());
    }
}
