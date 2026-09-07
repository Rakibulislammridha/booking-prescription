<?php

declare(strict_types=1);

use App\Http\Controllers\Panel\Billing\CashShiftController;
use App\Http\Controllers\Panel\Billing\CouponController;
use App\Http\Controllers\Panel\Billing\DiscountController;
use App\Http\Controllers\Panel\Billing\InvoiceController;
use App\Http\Controllers\Panel\Billing\PatientDuesController;
use App\Http\Controllers\Panel\Billing\PaymentController;
use App\Http\Controllers\Panel\Billing\PrintController;
use App\Http\Controllers\Panel\Billing\RefundController;
use App\Http\Controllers\Panel\Billing\ReportController;
use App\Http\Controllers\Panel\Billing\RevenueShareController;
use Illuminate\Support\Facades\Route;

// Billing (engineer B, names panel.billing.*): invoices and their detail, counter collection, discounts and
// coupons, refunds with reason codes, the cash drawer, commission rules, and the collection/commission reports.
// Authorisation is per action through InvoicePolicy / CouponPolicy / CashShiftPolicy / DoctorRevenueSharePolicy.
Route::prefix('billing')->name('billing.')->group(function (): void {
    Route::get('/', [InvoiceController::class, 'index'])->name('index');

    Route::prefix('invoices')->name('invoices.')->group(function (): void {
        Route::post('/', [InvoiceController::class, 'store'])->name('store');
        Route::get('{invoice:public_id}', [InvoiceController::class, 'show'])->name('show');
        Route::post('{invoice:public_id}/issue', [InvoiceController::class, 'issue'])->name('issue');
        Route::post('{invoice:public_id}/void', [InvoiceController::class, 'void'])->name('void');
        Route::post('{invoice:public_id}/payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::post('{invoice:public_id}/discounts', [DiscountController::class, 'store'])->name('discounts.store');
        Route::post('{invoice:public_id}/coupon', [DiscountController::class, 'coupon'])->name('coupon');
        Route::post('{invoice:public_id}/refunds', [RefundController::class, 'store'])->name('refunds.store');
        Route::post('{invoice:public_id}/refunds/{refund}', [RefundController::class, 'process'])->name('refunds.process');
        Route::get('{invoice:public_id}/refund-eligibility', [RefundController::class, 'eligibility'])->name('refunds.eligibility');
        Route::get('{invoice:public_id}/print', [PrintController::class, 'invoice'])->name('print');
        Route::get('{invoice:public_id}/receipt/{payment:public_id}', [PrintController::class, 'receipt'])->name('receipt');
    });

    // The outstanding-balance seam the desk board and the patient record read (both owned by other modules).
    Route::get('patients/{patient:public_id}/dues', PatientDuesController::class)->name('patients.dues');

    Route::get('coupons', [CouponController::class, 'index'])->name('coupons.index');
    Route::post('coupons', [CouponController::class, 'store'])->name('coupons.store');
    Route::patch('coupons/{coupon}', [CouponController::class, 'update'])->name('coupons.update');
    Route::delete('coupons/{coupon}', [CouponController::class, 'destroy'])->name('coupons.destroy');

    Route::get('shift', [CashShiftController::class, 'index'])->name('shift.index');
    Route::post('shift', [CashShiftController::class, 'open'])->name('shift.open');
    Route::post('shift/{shift}/close', [CashShiftController::class, 'close'])->name('shift.close');

    Route::get('revenue-shares', [RevenueShareController::class, 'index'])->name('revenue_shares.index');
    Route::post('revenue-shares', [RevenueShareController::class, 'store'])->name('revenue_shares.store');
    Route::patch('revenue-shares/{share}', [RevenueShareController::class, 'update'])->name('revenue_shares.update');
    Route::delete('revenue-shares/{share}', [RevenueShareController::class, 'destroy'])->name('revenue_shares.destroy');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');
});
