<?php

use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Features\Providers\FeaturesServiceProvider;
use App\Modules\Subscriptions\Providers\SubscriptionsServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    FeaturesServiceProvider::class,
    SubscriptionsServiceProvider::class,
    CatalogServiceProvider::class,
];
