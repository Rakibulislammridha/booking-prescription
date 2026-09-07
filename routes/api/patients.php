<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Patients\PatientByMobileController;
use App\Http\Controllers\Api\Patients\PatientSearchController;
use App\Http\Controllers\Api\Patients\PatientSummaryController;
use App\Http\Controllers\Api\Patients\RecentPatientsController;
use Illuminate\Support\Facades\Route;

// Staff JSON endpoints (names api.patients.*): quick search + recent list for the reception desk / offline cache
// warm-up, household by mobile for the booking flow, and the writer's PatientSummary. Same-origin staff session
// (Sanctum stateful cookie → auth:web) + PatientPolicy.
Route::prefix('patients')->name('patients.')->middleware('auth:web')->group(function (): void {
    Route::get('search', PatientSearchController::class)->name('search');
    Route::get('recent', RecentPatientsController::class)->name('recent');
    Route::get('by-mobile/{mobile}', PatientByMobileController::class)->name('by_mobile');
    Route::get('{patient:public_id}/summary', PatientSummaryController::class)->name('summary');
});
