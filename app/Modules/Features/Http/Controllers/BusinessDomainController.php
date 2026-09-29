<?php

namespace App\Modules\Features\Http\Controllers;

use App\Modules\Features\Models\BusinessDomain;
use App\Shared\Http\Controllers\ApiController;

class BusinessDomainController extends ApiController
{
    /**
     * GET /api/domaines-activite — the active domains a new store can be
     * created with (CreateStoreRequest only accepts an active one). Lets
     * the mobile onboarding offer a real list instead of hard-coding ids
     * that depend on seeding order.
     */
    public function index()
    {
        $domains = BusinessDomain::query()
            ->where('actif', true)
            ->orderBy('nom')
            ->get(['id', 'slug', 'nom', 'description', 'icone']);

        return $this->success($domains);
    }
}
