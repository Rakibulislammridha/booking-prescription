<?php

declare(strict_types=1);

use App\Http\Controllers\Panel\Notifications\GatewayController;
use App\Http\Controllers\Panel\Notifications\NotificationLogController;
use App\Http\Controllers\Panel\Notifications\PushSubscriptionController;
use App\Http\Controllers\Panel\Notifications\TemplateController;
use Illuminate\Support\Facades\Route;

// Notifications module, panel surface (names panel.notifications.*). Templates, gateways, notifications and push
// subscriptions have no `public_id` in SCHEMA, so they bind their bigint id — panel URLs only (CONVENTIONS §5).
Route::prefix('notifications')->name('notifications.')->group(function (): void {
    Route::get('/', [NotificationLogController::class, 'index'])->name('index');
    Route::get('{notification}', [NotificationLogController::class, 'show'])->whereNumber('notification')->name('show');
    Route::post('{notification}/retry', [NotificationLogController::class, 'retry'])->whereNumber('notification')->name('retry');

    Route::prefix('templates')->name('templates.')->group(function (): void {
        Route::get('/', [TemplateController::class, 'index'])->name('index');
        Route::post('/', [TemplateController::class, 'store'])->name('store');
        Route::post('preview', [TemplateController::class, 'preview'])->name('preview');
        Route::delete('{template}', [TemplateController::class, 'destroy'])->whereNumber('template')->name('destroy');
    });

    Route::prefix('gateways')->name('gateways.')->group(function (): void {
        Route::get('/', [GatewayController::class, 'index'])->name('index');
        Route::post('/', [GatewayController::class, 'store'])->name('store');
        Route::patch('{gateway}', [GatewayController::class, 'update'])->whereNumber('gateway')->name('update');
        Route::delete('{gateway}', [GatewayController::class, 'destroy'])->whereNumber('gateway')->name('destroy');
        Route::post('{gateway}/test', [GatewayController::class, 'test'])->whereNumber('gateway')->name('test');
    });

    Route::prefix('push')->name('push.')->group(function (): void {
        Route::get('/', [PushSubscriptionController::class, 'index'])->name('index');
        Route::delete('{subscription}', [PushSubscriptionController::class, 'destroy'])->whereNumber('subscription')->name('destroy');
    });
});
