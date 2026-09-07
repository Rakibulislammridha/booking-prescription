<?php

declare(strict_types=1);

use App\Http\Controllers\Super\Catalog\PromotionController;
use Illuminate\Support\Facades\Route;

// Custom-brand promotion review queue (CATALOG.md §8) on super.{central}; promotions bind by public_id (ULID).
Route::prefix('catalog/promotions')->name('catalog.promotions.')->group(function (): void {
    Route::get('/', [PromotionController::class, 'index'])->name('index');
    Route::get('{promotion:public_id}', [PromotionController::class, 'show'])->name('show');
    Route::post('{promotion:public_id}/approve', [PromotionController::class, 'approve'])->name('approve');
    Route::post('{promotion:public_id}/reject', [PromotionController::class, 'reject'])->name('reject');
});
