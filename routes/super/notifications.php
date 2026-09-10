<?php

declare(strict_types=1);

use App\Domain\SaaS\Http\Middleware\EnsureSuperAdminIsActive;
use App\Http\Controllers\Super\Notifications\PlatformNotificationController;
use Illuminate\Support\Facades\Route;

// Platform notification defaults on super.{central}: the outgoing email identity, the SMS gateway tenants inherit,
// the platform → owner mail templates (with preview and reset), a test send through the real drivers, and the
// outbound ledger. Every value is a `PlatformSettingsRegistry` key on the `notifications` screen, saved through the
// same audited action as the Platform settings page. The group already carries ['web', 'central', 'auth:super',
// 'idle:super', EnsureSuperTwoFactor] from bootstrap/app.php.
Route::middleware(EnsureSuperAdminIsActive::class)->prefix('notifications')->name('notifications.')->group(function (): void {
    Route::get('/', [PlatformNotificationController::class, 'index'])->name('index');
    Route::put('settings', [PlatformNotificationController::class, 'update'])->middleware('throttle:30,1,super-notifications')->name('update');
    Route::delete('templates/{key}', [PlatformNotificationController::class, 'reset'])->where('key', '[a-z0-9_.]+')->name('templates.reset');
    Route::post('templates/preview', [PlatformNotificationController::class, 'preview'])->name('templates.preview');
    Route::post('test', [PlatformNotificationController::class, 'test'])->middleware('throttle:10,1,super-notifications-test')->name('test');
});
