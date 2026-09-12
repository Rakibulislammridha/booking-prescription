<?php

declare(strict_types=1);

use App\Http\Controllers\Panel\Queue\CallNextVisitController;
use App\Http\Controllers\Panel\Queue\DoctorScreenController;
use App\Http\Controllers\Panel\Queue\QueueIndexController;
use App\Http\Controllers\Panel\Queue\TodayController;
use Illuminate\Support\Facades\Route;

// The staff side of the live queue (REALTIME.md §11, names panel.queue.*): the sidebar's landing (`index` — sends a
// doctor to their own screen, everyone else to the overview), the doctor's session page ("Today's session": now
// serving + the whole roster) with its roster refresh, the branch overview, and the post-issue "call next patient"
// (call-next + the called serial's visit in one request). The call-next / delay mutations themselves are the
// Serials module's endpoints (routes/panel/serials.php).
Route::prefix('queue')->name('queue.')->group(function (): void {
    Route::get('/', QueueIndexController::class)->name('index');
    Route::get('today', [TodayController::class, 'index'])->name('today');
    Route::get('today-data', [TodayController::class, 'data'])->name('today.data');
    Route::get('doctor', [DoctorScreenController::class, 'show'])->name('doctor');
    Route::get('doctor/roster', [DoctorScreenController::class, 'roster'])->name('doctor.roster');
    Route::post('sessions/{session:public_id}/call-next-visit', CallNextVisitController::class)->name('call-next-visit');
});
