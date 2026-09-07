<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Queue\DisplayBoardController;
use App\Http\Controllers\Api\Queue\SessionStateController;
use Illuminate\Support\Facades\Route;

// Queue reads for authenticated clients (names api.queue.*): the doctor screen and the waiting-room display address
// a session by public id rather than by doctor slug. The public, unauthenticated poll endpoint is the LOCKED
// site.queue.state in routes/site/queue.php; the mutations are api.sessions.* / api.serials.* (SERIAL_ENGINE §16).
Route::middleware('auth:sanctum,device')->prefix('queue')->name('queue.')->group(function (): void {
    Route::get('sessions/{session:public_id}/state', SessionStateController::class)->name('sessions.state');
    Route::get('display/{branch:public_id}', DisplayBoardController::class)->name('display.tiles');
});
