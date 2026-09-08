<?php

declare(strict_types=1);

use App\Domain\SaaS\Http\Middleware\SetCentralLocale;
use App\Http\Controllers\Central\BillingController;
use Illuminate\Support\Facades\Route;

// Paying a PLATFORM invoice, on the central host so a SUSPENDED tenant can still reach it (its own host answers
// 402 on everything). The page and the checkout are signed URLs minted by the dunning mail and the panel; the
// gateway return is not signed — a gateway appends its own query parameters and would break the signature — and
// is authenticated by the driver's own hash_equals check instead.
Route::middleware(SetCentralLocale::class)->group(function (): void {
    Route::get('billing/invoice/{invoice:public_id}', [BillingController::class, 'show'])->middleware('signed')->name('billing.invoice');
    Route::post('billing/invoice/{invoice:public_id}/pay', [BillingController::class, 'pay'])->middleware(['signed', 'throttle:10,1'])->name('billing.pay');
    Route::get('billing/callback/{gateway}', [BillingController::class, 'callback'])->middleware('throttle:60,1')->name('billing.callback');
});
