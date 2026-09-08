<?php

declare(strict_types=1);

use App\Domain\Telemedicine\Http\Middleware\EnsureTelemedicineEnabled;
use App\Domain\Telemedicine\Http\Middleware\RequirePatientSession;
use App\Domain\Telemedicine\Services\RoomName;
use App\Http\Controllers\Site\Telemedicine\BookingController;
use App\Http\Controllers\Site\Telemedicine\JoinLinkController;
use App\Http\Controllers\Site\Telemedicine\PatientCallController;
use App\Http\Controllers\Site\Telemedicine\WaitingRoomController;
use Illuminate\Support\Facades\Route;

// Telemedicine, public site (names site.telemedicine.*). Gated by the module's OWN middleware rather than the
// shared `plan:` alias: `plan:` redirects a refusal to the staff subscription page, which is the right answer
// for the clinic manager and the wrong one for a patient holding an SMS link. EnsureTelemedicineEnabled answers
// 402 with a page that explains the clinic does not offer video consultations (BRIEF §5.M gating).
//
// `/telemedicine/j/{room}` is the link in that SMS: signed, expiring, and the only route here without a guard —
// a valid signature logs the patient in on the `patient` guard and hands them to the waiting room.
Route::middleware(EnsureTelemedicineEnabled::class)->prefix('telemedicine')->name('telemedicine.')->group(function (): void {
    Route::get('/', [BookingController::class, 'index'])->name('book');
    Route::post('/', [BookingController::class, 'store'])->middleware('throttle:booking')->name('book.store');
    Route::get('booked/{appointment:public_id}', [BookingController::class, 'booked'])->name('booked');

    Route::get('j/{room}', JoinLinkController::class)->name('join')->where(['room' => RoomName::PATTERN]);

    Route::middleware(RequirePatientSession::class)->prefix('room/{room}')->group(function (): void {
        Route::get('/', [WaitingRoomController::class, 'show'])->name('room');
        Route::get('state', [WaitingRoomController::class, 'state'])->middleware('throttle:telemedicine-state')->name('room.state');
        Route::post('token', [PatientCallController::class, 'token'])->middleware('throttle:telemedicine-token')->name('room.token');
        Route::post('leave', [PatientCallController::class, 'leave'])->name('room.leave');
        Route::post('quality', [PatientCallController::class, 'quality'])->name('room.quality');
    })->where(['room' => RoomName::PATTERN]);
});
