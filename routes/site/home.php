<?php

declare(strict_types=1);

use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\LocaleController;
use Illuminate\Support\Facades\Route;

// Public landing page of a tenant host (site.home) and the language switch (site.locale) — CONVENTIONS §5.
Route::get('/', HomeController::class)->name('home');
Route::patch('locale', LocaleController::class)->name('locale');
