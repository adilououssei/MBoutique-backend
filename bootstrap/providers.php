<?php

use App\Modules\Appointments\Providers\AppointmentsServiceProvider;
use App\Modules\CashRegister\Providers\CashRegisterServiceProvider;
use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Customers\Providers\CustomersServiceProvider;
use App\Modules\Employees\Providers\EmployeesServiceProvider;
use App\Modules\Features\Providers\FeaturesServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Notifications\Providers\NotificationsServiceProvider;
use App\Modules\Orders\Providers\OrdersServiceProvider;
use App\Modules\Reports\Providers\ReportsServiceProvider;
use App\Modules\Sales\Providers\SalesServiceProvider;
use App\Modules\Subscriptions\Providers\SubscriptionsServiceProvider;
use App\Modules\Suppliers\Providers\SuppliersServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    FeaturesServiceProvider::class,
    SubscriptionsServiceProvider::class,
    CatalogServiceProvider::class,
    CustomersServiceProvider::class,
    InventoryServiceProvider::class,
    CashRegisterServiceProvider::class,
    SalesServiceProvider::class,
    SuppliersServiceProvider::class,
    EmployeesServiceProvider::class,
    AppointmentsServiceProvider::class,
    OrdersServiceProvider::class,
    NotificationsServiceProvider::class,
    ReportsServiceProvider::class,
];
