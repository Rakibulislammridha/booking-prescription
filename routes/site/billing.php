<?php

declare(strict_types=1);

use App\Http\Controllers\Site\Billing\CallbackController;
use App\Http\Controllers\Site\Billing\CheckoutController;
use Illuminate\Support\Facades\Route;

// Patient-facing online payment (engineer B, names site.billing.*). Public: a patient who just booked is not
// logged in. The amount is never taken from the request — it comes from the invoice built on the appointment's
// frozen fee snapshot — and starting a checkout is rate limited.
Route::prefix('pay')->name('billing.')->group(function (): void {
    Route::get('{appointment:public_id}', [CheckoutController::class, 'show'])->name('checkout');
    Route::post('{appointment:public_id}', [CheckoutController::class, 'start'])->middleware('throttle:billing-checkout')->name('checkout.start');
    Route::match(['get', 'post'], 'callback/{gateway}', CallbackController::class)->middleware('throttle:billing-webhook')->name('callback');
});
