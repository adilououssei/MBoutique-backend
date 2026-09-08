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

Route::get('/ping', fn () => response()->json(['success' => true, 'data' => ['pong' => true]]));

require app_path('Modules/Auth/Routes/api.php');
require app_path('Modules/Tenancy/Routes/api.php');
require app_path('Modules/Features/Routes/api.php');
require app_path('Modules/Catalog/Routes/api.php');
