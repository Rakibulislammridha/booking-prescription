<?php

declare(strict_types=1);

use App\Http\Controllers\Panel\Reception\AppointmentController;
use App\Http\Controllers\Panel\Reception\BoardController;
use App\Http\Controllers\Panel\Reception\BookingController;
use App\Http\Controllers\Panel\Reception\DeviceController;
use App\Http\Controllers\Panel\Reception\KioskController;
use App\Http\Controllers\Panel\Reception\PatientLookupController;
use App\Http\Controllers\Panel\Reception\PrintTemplateController;
use App\Http\Controllers\Panel\Reception\ShiftController;
use Illuminate\Support\Facades\Route;

// Reception desk (engineer R, names panel.reception.*): today's board (Inertia + polled JSON), counter booking,
// fee / cancel / reschedule, shift summary, device management, kiosk QR, print templates, patient quick search.
Route::prefix('reception')->name('reception.')->group(function (): void {
    Route::get('/', [BoardController::class, 'index'])->name('board');
    Route::get('board-data', [BoardController::class, 'data'])->name('board.data');
    Route::get('shift', [ShiftController::class, 'index'])->name('shift');
    Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::post('devices/{device:public_id}/revoke', [DeviceController::class, 'revoke'])->name('devices.revoke');
    Route::get('kiosk-url', KioskController::class)->name('kiosk_url');
    Route::get('print-templates', PrintTemplateController::class)->name('print_templates');
    Route::get('patients/lookup', PatientLookupController::class)->name('patients.lookup');
    Route::post('bookings', [BookingController::class, 'store'])->name('bookings.store');
    Route::post('appointments/{appointment:public_id}/collect', [AppointmentController::class, 'collect'])->name('appointments.collect');
    Route::post('appointments/{appointment:public_id}/cancel', [AppointmentController::class, 'cancel'])->name('appointments.cancel');
    Route::post('appointments/{appointment:public_id}/reschedule', [AppointmentController::class, 'reschedule'])->name('appointments.reschedule');
});
