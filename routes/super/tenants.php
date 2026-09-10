<?php

declare(strict_types=1);

use App\Domain\SaaS\Http\Middleware\EnsureSuperAdminIsActive;
use App\Http\Controllers\Super\TenantController;
use App\Http\Controllers\Super\Tenants\BillingController;
use App\Http\Controllers\Super\Tenants\DataController;
use App\Http\Controllers\Super\Tenants\DeletionController;
use App\Http\Controllers\Super\Tenants\DomainController;
use App\Http\Controllers\Super\Tenants\EntitlementController;
use App\Http\Controllers\Super\Tenants\ImpersonationController;
use App\Http\Controllers\Super\Tenants\LifecycleController;
use App\Http\Controllers\Super\Tenants\SlugCheckController;
use App\Http\Controllers\Super\Tenants\SlugController;
use App\Http\Controllers\Super\Tenants\StaffController;
use Illuminate\Support\Facades\Route;

// Tenant list, detail and every operational action, on super.{central}. Tenants bind by public_id (ULID) —
// the bigint id never appears in a URL (CONVENTIONS §5). The literal segments (`create`, `slug-check`) are
// registered BEFORE `{tenant:public_id}` so the binding never swallows them.
Route::middleware(EnsureSuperAdminIsActive::class)->prefix('tenants')->name('tenants.')->group(function (): void {
    Route::get('/', [TenantController::class, 'index'])->name('index');
    Route::get('create', [TenantController::class, 'create'])->name('create');
    Route::post('/', [TenantController::class, 'store'])->name('store');
    Route::get('slug-check', SlugCheckController::class)->middleware('throttle:120,1,super-slug-check')->name('slug-check');

    Route::get('{tenant:public_id}', [TenantController::class, 'show'])->name('show');
    Route::get('{tenant:public_id}/edit', [TenantController::class, 'edit'])->name('edit');
    Route::put('{tenant:public_id}', [TenantController::class, 'update'])->name('update');
    Route::delete('{tenant:public_id}', [TenantController::class, 'destroy'])->name('destroy');
    Route::post('{tenant:public_id}/slug', SlugController::class)->name('slug');
    Route::post('{tenant:public_id}/deletion/prepare', DeletionController::class)->name('deletion.prepare');

    Route::post('{tenant:public_id}/suspend', [LifecycleController::class, 'suspend'])->name('suspend');
    Route::post('{tenant:public_id}/reactivate', [LifecycleController::class, 'reactivate'])->name('reactivate');
    Route::post('{tenant:public_id}/cancel', [LifecycleController::class, 'cancel'])->name('cancel');
    Route::post('{tenant:public_id}/plan', [LifecycleController::class, 'plan'])->name('plan');

    Route::post('{tenant:public_id}/features', [EntitlementController::class, 'feature'])->name('features');
    Route::post('{tenant:public_id}/limits', [EntitlementController::class, 'limits'])->name('limits');

    Route::post('{tenant:public_id}/invoices', [BillingController::class, 'store'])->name('invoices.store');
    Route::post('{tenant:public_id}/invoices/{invoice:public_id}/issue', [BillingController::class, 'issue'])->name('invoices.issue');
    Route::post('{tenant:public_id}/invoices/{invoice:public_id}/void', [BillingController::class, 'void'])->name('invoices.void');
    Route::post('{tenant:public_id}/invoices/{invoice:public_id}/payments', [BillingController::class, 'pay'])->name('invoices.pay');

    Route::post('{tenant:public_id}/impersonate', ImpersonationController::class)->name('impersonate');

    // Staff of the clinic: `{user}` is the user's public_id INSIDE that clinic's schema, resolved by the action
    // under Tenancy::run() — never a bigint, and never bound by the router (which runs on `public`).
    Route::post('{tenant:public_id}/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::post('{tenant:public_id}/staff/{user}/password', [StaffController::class, 'resetPassword'])->where('user', '[0-9A-Za-z]{26}')->name('staff.password');
    Route::post('{tenant:public_id}/staff/{user}/status', [StaffController::class, 'status'])->where('user', '[0-9A-Za-z]{26}')->name('staff.status');

    Route::post('{tenant:public_id}/backups', [DataController::class, 'backup'])->name('backups.store');
    Route::post('{tenant:public_id}/exports', [DataController::class, 'export'])->name('exports.store');
    // `withTrashed`: a deleted clinic's churn export stays downloadable from the deleted-clinics list (BRIEF §5.N).
    Route::get('{tenant:public_id}/archives/{backup}/download', [DataController::class, 'download'])->withTrashed()->name('archives.download');
    Route::post('{tenant:public_id}/archives/{backup}/restore', [DataController::class, 'restore'])->name('archives.restore');

    Route::post('{tenant:public_id}/domains', [DomainController::class, 'store'])->name('domains.store');
    Route::post('{tenant:public_id}/domains/{domain}/verify', [DomainController::class, 'verify'])->middleware('throttle:saas-domain-verify')->name('domains.verify');
    Route::post('{tenant:public_id}/domains/{domain}/primary', [DomainController::class, 'primary'])->name('domains.primary');
    Route::delete('{tenant:public_id}/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
});
