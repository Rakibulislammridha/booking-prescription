<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Catalog\DrugSearchController;
use App\Http\Controllers\Api\Catalog\GenericLookupController;
use App\Http\Controllers\Api\Catalog\Icd10SearchController;
use App\Http\Controllers\Api\Catalog\VocabularyController;
use Illuminate\Support\Facades\Route;

// Autocomplete reads for the staff session (PRESCRIPTION.md §3; CATALOG.md §4). JSON only; rate-limited per user.
Route::middleware(['auth:web', 'throttle:catalog-search'])->prefix('catalog')->name('catalog.')->group(function (): void {
    Route::get('drugs', DrugSearchController::class)->name('drugs');
    Route::get('icd10', Icd10SearchController::class)->name('icd10');
    Route::get('generics', GenericLookupController::class)->name('generics');
    Route::get('vocabulary', VocabularyController::class)->name('vocabulary');
});
