<?php

declare(strict_types=1);

use App\Domain\Reception\Http\Middleware\AuthenticateReceptionDevice;
use App\Http\Controllers\Api\Reception\BlockController;
use App\Http\Controllers\Api\Reception\BoardController;
use App\Http\Controllers\Api\Reception\BootstrapController;
use App\Http\Controllers\Api\Reception\DeviceController;
use App\Http\Controllers\Api\Reception\PatientController;
use App\Http\Controllers\Api\Reception\PrintTemplateController;
use App\Http\Controllers\Api\Reception\SyncController;
use Illuminate\Support\Facades\Route;

// The reception device API (OFFLINE.md §2, §4, §7; names api.reception.*). Registration uses the staff session
// (auth:sanctum + permission reception.devices.register); everything else authenticates the DEVICE by bearer token
// and the PERSON by X-Actor-User through AuthenticateReceptionDevice (applied by class, per-route ability).
Route::prefix('reception')->name('reception.')->group(function (): void {
    Route::post('devices/register', [DeviceController::class, 'register'])->middleware('auth:sanctum')->name('devices.register');

    Route::middleware(AuthenticateReceptionDevice::class.':reception:read')->group(function (): void {
        Route::get('bootstrap', BootstrapController::class)->name('bootstrap');
        Route::get('board', BoardController::class)->name('board');
        Route::get('print-templates', PrintTemplateController::class)->name('print-templates');
        Route::get('patients/recent', [PatientController::class, 'recent'])->name('patients.recent');
        Route::get('patients/lookup', [PatientController::class, 'lookup'])->name('patients.lookup');
        Route::get('sync/conflicts', [SyncController::class, 'conflicts'])->name('sync.conflicts');
    });

    Route::middleware(AuthenticateReceptionDevice::class.':reception:blocks')->group(function (): void {
        Route::get('blocks', [BlockController::class, 'index'])->name('blocks.index');
        Route::post('blocks/lease', [BlockController::class, 'lease'])->name('blocks.lease');
        Route::post('blocks/{block:public_id}/release', [BlockController::class, 'release'])->name('blocks.release');
        Route::post('blocks/{block:public_id}/revoke', [BlockController::class, 'revoke'])->name('blocks.revoke');
        Route::post('devices/{device:public_id}/revoke', [DeviceController::class, 'revoke'])->name('devices.revoke');
    });

    Route::middleware(AuthenticateReceptionDevice::class.':reception:sync')->group(function (): void {
        Route::post('sync', [SyncController::class, 'store'])->name('sync');
        Route::post('sync/resolve', [SyncController::class, 'resolve'])->name('sync.resolve');
    });
});
