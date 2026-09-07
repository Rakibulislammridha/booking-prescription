<?php

declare(strict_types=1);

use App\Http\Controllers\Panel\Catalog\CustomBrandController;
use Illuminate\Support\Facades\Route;

// Tenant custom brands (CATALOG.md §8). Panel URLs use the bigint id (no public_id on custom_brands, CONVENTIONS §5).
Route::prefix('custom-brands')->name('catalog.custom-brands.')->group(function (): void {
    Route::get('/', [CustomBrandController::class, 'index'])->name('index');
    Route::post('/', [CustomBrandController::class, 'store'])->name('store');
    Route::put('{customBrand}', [CustomBrandController::class, 'update'])->name('update');
    Route::delete('{customBrand}', [CustomBrandController::class, 'destroy'])->name('destroy');
});
