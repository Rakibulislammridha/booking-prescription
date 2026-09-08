<?php

declare(strict_types=1);

use App\Domain\Telemedicine\Services\RoomName;
use App\Http\Controllers\Panel\Telemedicine\CallController;
use App\Http\Controllers\Panel\Telemedicine\ConsoleController;
use App\Http\Controllers\Panel\Telemedicine\RecordingController;
use Illuminate\Support\Facades\Route;

// Telemedicine, panel surface (names panel.telemedicine.*). The whole group is behind the `plan:telemedicine`
// add-on gate (BRIEF §5.M): a clinic without it is redirected to its subscription page with an upgrade message,
// never a 404. `{room}` binds `telemedicine_rooms.room_name` (`t{tenantId}-{ulid}`) — that column IS the row's
// public handle (SCHEMA §3.8: "never guessable"), and the pattern keeps a probe out of the database entirely.
Route::middleware('plan:telemedicine')->prefix('telemedicine')->name('telemedicine.')->group(function (): void {
    Route::get('/', [ConsoleController::class, 'index'])->name('index');

    Route::prefix('{room}')->group(function (): void {
        Route::get('/', [ConsoleController::class, 'show'])->name('console');
        Route::post('start', [CallController::class, 'start'])->name('start');
        Route::post('end', [CallController::class, 'end'])->name('end');
        Route::post('token', [CallController::class, 'token'])->name('token')->middleware('throttle:telemedicine-token');
        Route::post('leave', [CallController::class, 'leave'])->name('leave');
        Route::post('recording', [RecordingController::class, 'update'])->name('recording');
        Route::get('state', [CallController::class, 'state'])->name('state')->middleware('throttle:telemedicine-state');
        Route::post('quality', [CallController::class, 'quality'])->name('quality');
    })->where(['room' => RoomName::PATTERN]);
});
