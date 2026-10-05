<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Every module owns its own route file under app/Modules/{Module}/Routes/api.php
| and is included below. This keeps route registration discoverable from
| one place while letting each module stay self-contained. See
| docs/modules.md for the convention.
|
*/

Route::get('/ping', fn () => response()->json(['succes' => true, 'donnees' => ['pong' => true]]));

require app_path('Modules/Auth/Routes/api.php');
require app_path('Modules/Tenancy/Routes/api.php');
require app_path('Modules/Features/Routes/api.php');
require app_path('Modules/Catalog/Routes/api.php');
require app_path('Modules/Customers/Routes/api.php');
require app_path('Modules/Inventory/Routes/api.php');
require app_path('Modules/CashRegister/Routes/api.php');
require app_path('Modules/Sales/Routes/api.php');
require app_path('Modules/Suppliers/Routes/api.php');
require app_path('Modules/Employees/Routes/api.php');
require app_path('Modules/Appointments/Routes/api.php');
require app_path('Modules/Orders/Routes/api.php');
require app_path('Modules/Notifications/Routes/api.php');
require app_path('Modules/Reports/Routes/api.php');
