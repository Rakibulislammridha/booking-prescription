<?php

declare(strict_types=1);

use App\Domain\SaaS\Http\Middleware\SetCentralLocale;
use App\Http\Controllers\Central\ChangelogController;
use App\Http\Controllers\Central\DocsController;
use App\Http\Controllers\Central\HomeController;
use App\Http\Controllers\Central\LocaleController;
use App\Http\Controllers\Central\PricingController;
use Illuminate\Support\Facades\Route;

// The platform's public marketing surface on the bare central domain (and www.) — names `central.*`, site bundle.
// Globbed by bootstrap/app.php inside ['web', 'central'], so RequireCentral has already 404'd any tenant host.
Route::middleware(SetCentralLocale::class)->group(function (): void {
    Route::get('/', HomeController::class)->name('home');
    Route::get('pricing', PricingController::class)->name('pricing');
    Route::get('changelog', ChangelogController::class)->name('changelog');
    Route::get('docs', [DocsController::class, 'index'])->name('docs.index');
    Route::get('docs/{section}', [DocsController::class, 'show'])->name('docs.show');
    Route::patch('locale', LocaleController::class)->name('locale');
});
