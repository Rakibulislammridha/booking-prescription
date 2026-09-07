<?php

declare(strict_types=1);

use App\Http\Controllers\Site\Prescription\DrugInfoController;
use App\Http\Controllers\Site\Prescription\VerificationController;
use Illuminate\Support\Facades\Route;

// The public prescription pages (PRESCRIPTION.md §7.4, §7.8): no auth; the tenant is resolved by the host.
// Both are throttled — the verification code is the only credential, so an unthrottled endpoint is an oracle.
Route::get('rx/{code}', [VerificationController::class, 'show'])->middleware('throttle:rx-verify')->name('prescription.verify');
Route::get('rx/{code}/pdf', [VerificationController::class, 'pdf'])->middleware('throttle:rx-verify')->name('prescription.verify.pdf');
Route::get('drug/{slug}', [DrugInfoController::class, 'show'])->middleware('throttle:rx-verify')->name('prescription.drug');
