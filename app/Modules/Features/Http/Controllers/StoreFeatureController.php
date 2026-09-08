<?php

namespace App\Modules\Features\Http\Controllers;

use App\Modules\Features\Services\FeatureGate;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;

class StoreFeatureController extends ApiController
{
    public function __construct(private readonly FeatureGate $featureGate) {}

    /**
     * GET /api/stores/{store}/features — lets the mobile/React client
     * build its navigation dynamically instead of re-implementing
     * FeatureGate's resolution logic itself. See docs/feature-gate.md.
     */
    public function index(Store $store)
    {
        $this->authorize('view', $store);

        $store->loadMissing('businessDomain');

        $resolved = $this->featureGate->resolveForStore($store);

        return $this->success([
            'domain' => [
                'slug' => $store->businessDomain->slug,
                'name' => $store->businessDomain->name,
            ],
            'features' => $resolved->map(fn (bool $enabled, string $slug) => [
                'slug' => $slug,
                'enabled' => $enabled,
            ])->values(),
        ]);
    }
}
