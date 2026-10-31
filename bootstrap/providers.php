<?php

use App\Modules\Admin\Providers\AdminServiceProvider;
use App\Modules\Appointments\Providers\AppointmentsServiceProvider;
use App\Modules\CashRegister\Providers\CashRegisterServiceProvider;
use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Conservation\Providers\ConservationServiceProvider;
use App\Modules\Customers\Providers\CustomersServiceProvider;
use App\Modules\Devices\Providers\DevicesServiceProvider;
use App\Modules\Employees\Providers\EmployeesServiceProvider;
use App\Modules\Equipment\Providers\EquipmentServiceProvider;
use App\Modules\Expenses\Providers\ExpensesServiceProvider;
use App\Modules\Features\Providers\FeaturesServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Notifications\Providers\NotificationsServiceProvider;
use App\Modules\Orders\Providers\OrdersServiceProvider;
use App\Modules\Repairs\Providers\RepairsServiceProvider;
use App\Modules\Reports\Providers\ReportsServiceProvider;
use App\Modules\Returns\Providers\ReturnsServiceProvider;
use App\Modules\Sales\Providers\SalesServiceProvider;
use App\Modules\Subscriptions\Providers\SubscriptionsServiceProvider;
use App\Modules\Suppliers\Providers\SuppliersServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Modules\Transformations\Providers\TransformationsServiceProvider;
use App\Modules\Warranties\Providers\WarrantiesServiceProvider;
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
    TransformationsServiceProvider::class,
    ConservationServiceProvider::class,
    ExpensesServiceProvider::class,
    EquipmentServiceProvider::class,
    DevicesServiceProvider::class,
    WarrantiesServiceProvider::class,
    ReturnsServiceProvider::class,
    RepairsServiceProvider::class,
    EmployeesServiceProvider::class,
    AppointmentsServiceProvider::class,
    OrdersServiceProvider::class,
    NotificationsServiceProvider::class,
    ReportsServiceProvider::class,
    AdminServiceProvider::class,
];
