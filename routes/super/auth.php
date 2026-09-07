<?php

declare(strict_types=1);

use App\Http\Controllers\Super\Auth\LoginController;
use App\Http\Controllers\Super\DashboardController;
use Illuminate\Support\Facades\Route;

// Super-admin session (guard super) on super.{central}. The login pair opts out of the group's auth:super.
Route::get('login', [LoginController::class, 'create'])->middleware('guest:super')->withoutMiddleware('auth:super')->name('login');
Route::post('login', [LoginController::class, 'store'])->middleware(['guest:super', 'throttle:10,1'])->withoutMiddleware('auth:super')->name('login.store');
Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

Route::get('/', DashboardController::class)->name('dashboard');
