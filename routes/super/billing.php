<?php

declare(strict_types=1);

use App\Domain\SaaS\Http\Middleware\EnsureSuperAdminIsActive;
use App\Http\Controllers\Super\Billing\DunningController;
use App\Http\Controllers\Super\Billing\ExportController;
use App\Http\Controllers\Super\Billing\InvoiceController;
use App\Http\Controllers\Super\Billing\OverviewController;
use App\Http\Controllers\Super\Billing\PaymentController;
use App\Http\Controllers\Super\Billing\SubscriptionController;
use Illuminate\Support\Facades\Route;

// The platform's own billing desk on super.{central} (BRIEF §5.M: "subscription billing, invoices, dunning,
// auto-suspend"). Invoices bind by public_id (ULID); subscription actions bind by the TENANT's public_id and act
// on its current subscription (subscriptions carry no public_id, CONVENTIONS §5). Exports are GETs that stream
// CSV and are audited like any other read that leaves the building.
Route::middleware(EnsureSuperAdminIsActive::class)->prefix('billing')->name('billing.')->group(function (): void {
    Route::get('/', OverviewController::class)->name('index');

    Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('subscriptions/{tenant:public_id}/plan', [SubscriptionController::class, 'plan'])->name('subscriptions.plan');
    Route::post('subscriptions/{tenant:public_id}/end-trial', [SubscriptionController::class, 'endTrial'])->name('subscriptions.end-trial');
    Route::post('subscriptions/{tenant:public_id}/dun', [SubscriptionController::class, 'dun'])->name('subscriptions.dun');
    Route::post('subscriptions/{tenant:public_id}/suspend', [SubscriptionController::class, 'suspend'])->name('subscriptions.suspend');
    Route::post('subscriptions/{tenant:public_id}/reactivate', [SubscriptionController::class, 'reactivate'])->name('subscriptions.reactivate');
    Route::post('subscriptions/{tenant:public_id}/cancel', [SubscriptionController::class, 'cancel'])->name('subscriptions.cancel');

    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::post('invoices/{invoice:public_id}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
    Route::post('invoices/{invoice:public_id}/void', [InvoiceController::class, 'void'])->name('invoices.void');
    Route::post('invoices/{invoice:public_id}/payments', [InvoiceController::class, 'pay'])->name('invoices.pay');
    Route::get('invoices/{invoice:public_id}/print', [InvoiceController::class, 'print'])->name('invoices.print');
    Route::get('invoices/{invoice:public_id}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');

    Route::get('payments', PaymentController::class)->name('payments.index');

    Route::get('dunning', [DunningController::class, 'index'])->name('dunning.index');
    Route::post('dunning/{tenant:public_id}/run', [DunningController::class, 'run'])->name('dunning.run');

    Route::get('export/{kind}', ExportController::class)->where('kind', 'subscriptions|invoices|payments')->name('export');
});
