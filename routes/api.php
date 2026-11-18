<?php

/*
|--------------------------------------------------------------------------
 routes/api.php — ADD this route (column reduction 2026-11-18)
|--------------------------------------------------------------------------
 Place it with the other Mirth API routes. It is public (machine-keyed by
 device_model in the path) so the Mirth sync channels can fetch + cache the
 machine test mappings once per channel start.

 MachineConfigController.php is a NEW file — copy it to
 app/Http/Controllers/MachineConfigController.php
*/

use App\Http\Controllers\MachineConfigController;
use Illuminate\Support\Facades\Route;

// Machine config endpoint (Mirth fetches + caches once per channel start)
Route::get('/mirth/machine-config/{device_model}', [MachineConfigController::class, 'show']);
