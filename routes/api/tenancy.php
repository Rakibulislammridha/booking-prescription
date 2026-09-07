<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Tenancy\PingController;
use Illuminate\Support\Facades\Route;

// GET /api/ping — heartbeat for the connection store (OFFLINE.md §3.1). No auth; throttle:api applies.
Route::get('ping', PingController::class)->name('tenancy.ping');
