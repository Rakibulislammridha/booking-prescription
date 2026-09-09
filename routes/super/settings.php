<?php

declare(strict_types=1);

use App\Domain\SaaS\Http\Middleware\EnsureSuperAdminIsActive;
use App\Http\Controllers\Super\PlatformSettingController;
use Illuminate\Support\Facades\Route;

// Platform settings on super.{central} (SCHEMA §2.19): the closed registry rendered as a screen, one key saved
// at a time. `{key}` is the dotted registry key (`security.super_two_factor`). The group already carries
// ['web', 'central', 'auth:super', 'idle:super', EnsureSuperTwoFactor] from bootstrap/app.php; the two-factor
// gate lets an operator held on forced enrolment reach `super.settings.*` on purpose — the policy that holds
// them there is set here (ARCHITECTURE §6.5). Saving a `reauth` key re-asks the password, so the write is
// throttled like the login it resembles — in its own bucket (the prefix), so the console's heartbeat, which
// counts against a plain numeric limiter's per-operator key, cannot spend it (see `api/ping` in saas.php).
Route::middleware(EnsureSuperAdminIsActive::class)->prefix('settings')->name('settings.')->group(function (): void {
    Route::get('/', [PlatformSettingController::class, 'index'])->name('index');
    Route::put('{key}', [PlatformSettingController::class, 'update'])->where('key', '[a-z0-9_.]+')->middleware('throttle:10,1,super-settings')->name('update');
});
