<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Notifications\DeliveryReceiptController;
use App\Http\Controllers\Api\Notifications\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

// Notifications module, api surface (names api.notifications.*).
//
// The push endpoints are same-origin and belong to whoever is signed in (staff `web` session on the panel, patient
// session on the portal). The DLR webhook has no session at all: it is authenticated by the per-gateway secret in
// the row's encrypted credentials and answers 404 to anything else (CONVENTIONS §13 — webhooks verify signatures).
Route::prefix('notifications')->name('notifications.')->group(function (): void {
    Route::middleware('auth:web,patient')->group(function (): void {
        Route::get('push/key', [PushSubscriptionController::class, 'key'])->name('push.key');
        Route::post('push/subscribe', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
        Route::delete('push/subscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');
    });

    Route::post('dlr/{gateway}', DeliveryReceiptController::class)->whereNumber('gateway')->middleware('throttle:60,1')->name('dlr');
});
