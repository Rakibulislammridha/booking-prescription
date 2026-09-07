<?php

declare(strict_types=1);

use App\Http\Controllers\Panel\Queue\DoctorScreenController;
use App\Http\Controllers\Panel\Queue\TodayController;
use Illuminate\Support\Facades\Route;

// The staff side of the live queue (REALTIME.md §11, names panel.queue.*): the doctor's call-next screen and the
// branch overview. The call-next / delay mutations are the Serials module's endpoints (routes/panel/serials.php).
Route::prefix('queue')->name('queue.')->group(function (): void {
    Route::get('/', [TodayController::class, 'index'])->name('today');
    Route::get('today-data', [TodayController::class, 'data'])->name('today.data');
    Route::get('doctor', DoctorScreenController::class)->name('doctor');
});
