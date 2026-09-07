<?php

declare(strict_types=1);

use App\Http\Controllers\Panel\Serials\SerialActionController;
use App\Http\Controllers\Panel\Serials\SerialController;
use App\Http\Controllers\Panel\Serials\SessionActionController;
use Illuminate\Support\Facades\Route;

// SERIAL_ENGINE §16: api.sessions.* / api.serials.* for the desk PWA and the doctor screen (Sanctum: stateful staff
// cookie or bearer token). Offline replay never calls these; it goes through POST /api/reception/sync (R).
Route::middleware('auth:sanctum')->group(function (): void {
    Route::prefix('sessions/{session:public_id}')->name('sessions.')->group(function (): void {
        Route::get('/', [SessionActionController::class, 'show'])->name('show');
        Route::get('capacity', [SessionActionController::class, 'capacity'])->name('capacity');
        Route::post('serials', [SerialController::class, 'store'])->name('serials.store');
        Route::post('call-next', [SessionActionController::class, 'callNext'])->name('call-next');
        Route::post('start', [SessionActionController::class, 'start'])->name('start');
        Route::post('pause', [SessionActionController::class, 'pause'])->name('pause');
        Route::post('resume', [SessionActionController::class, 'resume'])->name('resume');
        Route::post('close', [SessionActionController::class, 'close'])->name('close');
        Route::post('cancel', [SessionActionController::class, 'cancel'])->name('cancel');
        Route::post('delay', [SessionActionController::class, 'delay'])->name('delay');
        Route::post('extend', [SessionActionController::class, 'extend'])->name('extend');
        Route::post('transfer', [SessionActionController::class, 'transfer'])->name('transfer');
        Route::post('pools/release-online', [SessionActionController::class, 'releaseOnline'])->name('pools.release-online');
        Route::put('pools/split', [SessionActionController::class, 'split'])->name('pools.split');
    });

    Route::prefix('serials/{serial:public_id}')->name('serials.')->group(function (): void {
        Route::post('check-in', [SerialActionController::class, 'checkIn'])->name('check-in');
        Route::post('call', [SerialActionController::class, 'call'])->name('call');
        Route::post('start', [SerialActionController::class, 'start'])->name('start');
        Route::post('complete', [SerialActionController::class, 'complete'])->name('complete');
        Route::post('skip', [SerialActionController::class, 'skip'])->name('skip');
        Route::post('return', [SerialActionController::class, 'return'])->name('return');
        Route::post('no-show', [SerialActionController::class, 'noShow'])->name('no-show');
        Route::post('reinstate', [SerialActionController::class, 'reinstate'])->name('reinstate');
        Route::post('cancel', [SerialActionController::class, 'cancel'])->name('cancel');
        Route::post('postpone', [SerialActionController::class, 'postpone'])->name('postpone');
        Route::post('transfer', [SerialActionController::class, 'transfer'])->name('transfer');
        Route::post('reorder', [SerialActionController::class, 'reorder'])->name('reorder');
        Route::post('priority', [SerialActionController::class, 'priority'])->name('priority');
    });
});
