<?php

declare(strict_types=1);

use App\Http\Controllers\Site\Queue\DisplayPageController;
use App\Http\Controllers\Site\Queue\QueuePageController;
use App\Http\Controllers\Site\Queue\QueueSessionsController;
use App\Http\Controllers\Site\Queue\QueueStateController;
use App\Http\Controllers\Site\Queue\ResolveLocalSerialController;
use Illuminate\Support\Facades\Route;

// The public live queue (REALTIME.md §5.1, names site.queue.*): no login, no PII — codes and statuses only.
// The vanity group is declared FIRST so queue.{host}/{doctorSlug}/today wins over any later unconstrained site route;
// the `queue.` label is stripped by TenantResolver (config/tenancy.php service_prefixes) before the host lookup.
Route::domain('queue.{tenantHost}')->where(['tenantHost' => '.+'])->group(function (): void {
    Route::get('/{doctorSlug}/today', QueuePageController::class)->name('queue.vanity');
});

Route::get('/q/{doctorSlug}/today', QueuePageController::class)->name('queue.page');
Route::get('/q/resolve/{localId}', ResolveLocalSerialController::class)->where('localId', '[A-Za-z0-9:_-]+')->name('queue.resolve');
Route::get('/display/{branchSlug}', DisplayPageController::class)->name('queue.display');
Route::get('/queue/{doctorSlug}/state', QueueStateController::class)->middleware('throttle:queue-state')->name('queue.state');   // LOCKED path
Route::get('/queue/{doctorSlug}/sessions', QueueSessionsController::class)->name('queue.sessions');
