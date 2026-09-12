<?php

declare(strict_types=1);

use App\Http\Controllers\Panel\Clinic\BranchController;
use App\Http\Controllers\Panel\Clinic\DepartmentController;
use App\Http\Controllers\Panel\Clinic\DoctorController;
use App\Http\Controllers\Panel\Clinic\DoctorLeaveController;
use App\Http\Controllers\Panel\Clinic\DoctorPhotoController;
use App\Http\Controllers\Panel\Clinic\HolidayController;
use App\Http\Controllers\Panel\Clinic\PadDesignerController;
use App\Http\Controllers\Panel\Clinic\SettingsController;
use App\Http\Controllers\Panel\Clinic\SpecialtyController;
use App\Http\Controllers\Panel\Clinic\StaffSessionController;
use App\Http\Controllers\Panel\Clinic\StaffUserController;
use Illuminate\Support\Facades\Route;

// Clinic module, panel surface (names panel.clinic.*) — the hospital setup screens of BRIEF §5.A.
// {branch}, {user} and {doctor} bind their public_id ULID (CONVENTIONS §5); departments, specialties, holidays and
// doctor leaves have no public_id in SCHEMA §3.1 and bind their bigint id, in panel URLs only.
// Every route is authorised by the Clinic policies (ClinicServiceProvider) inside the controller or FormRequest.
Route::prefix('clinic')->name('clinic.')->group(function (): void {
    Route::prefix('branches')->name('branches.')->group(function (): void {
        Route::get('/', [BranchController::class, 'index'])->name('index');
        Route::get('create', [BranchController::class, 'create'])->name('create');
        Route::post('/', [BranchController::class, 'store'])->name('store');
        Route::get('{branch:public_id}/edit', [BranchController::class, 'edit'])->name('edit');
        Route::put('{branch:public_id}', [BranchController::class, 'update'])->name('update');
        Route::patch('{branch:public_id}/status', [BranchController::class, 'status'])->name('status');
        Route::post('{branch:public_id}/main', [BranchController::class, 'main'])->name('main');
    });

    Route::prefix('departments')->name('departments.')->group(function (): void {
        Route::get('/', [DepartmentController::class, 'index'])->name('index');
        Route::post('/', [DepartmentController::class, 'store'])->name('store');
        Route::put('{department}', [DepartmentController::class, 'update'])->whereNumber('department')->name('update');
        Route::delete('{department}', [DepartmentController::class, 'destroy'])->whereNumber('department')->name('destroy');
    });

    Route::prefix('specialties')->name('specialties.')->group(function (): void {
        Route::get('/', [SpecialtyController::class, 'index'])->name('index');
        Route::post('/', [SpecialtyController::class, 'store'])->name('store');
        Route::put('{specialty}', [SpecialtyController::class, 'update'])->whereNumber('specialty')->name('update');
        Route::delete('{specialty}', [SpecialtyController::class, 'destroy'])->whereNumber('specialty')->name('destroy');
    });

    Route::prefix('staff')->name('staff.')->group(function (): void {
        Route::get('/', [StaffUserController::class, 'index'])->name('index');
        Route::get('create', [StaffUserController::class, 'create'])->name('create');
        Route::post('/', [StaffUserController::class, 'store'])->name('store');
        Route::get('{user:public_id}/edit', [StaffUserController::class, 'edit'])->name('edit');
        Route::put('{user:public_id}', [StaffUserController::class, 'update'])->name('update');
        Route::patch('{user:public_id}/status', [StaffUserController::class, 'status'])->name('status');
        Route::post('{user:public_id}/password-reset', [StaffUserController::class, 'sendPasswordReset'])->name('password_reset');

        // Device management (BRIEF §5.N). A session is addressed by an opaque ref, never by its id.
        Route::get('{user:public_id}/sessions', [StaffSessionController::class, 'index'])->name('sessions.index');
        Route::delete('{user:public_id}/sessions', [StaffSessionController::class, 'destroyOthers'])->name('sessions.destroy_others');
        Route::delete('{user:public_id}/sessions/{ref}', [StaffSessionController::class, 'destroy'])->whereAlphaNumeric('ref')->name('sessions.destroy');
    });

    Route::prefix('doctors')->name('doctors.')->group(function (): void {
        Route::get('/', [DoctorController::class, 'index'])->name('index');
        Route::get('create', [DoctorController::class, 'create'])->name('create');
        Route::post('/', [DoctorController::class, 'store'])->name('store');
        Route::get('{doctor:public_id}/edit', [DoctorController::class, 'edit'])->name('edit');
        Route::put('{doctor:public_id}', [DoctorController::class, 'update'])->name('update');

        Route::post('{doctor:public_id}/photo', [DoctorPhotoController::class, 'store'])->name('photo.store');
        Route::get('{doctor:public_id}/photo', [DoctorPhotoController::class, 'show'])->name('photo.show');

        // Pad designer (BRIEF §5.A, PRESCRIPTION.md §7.1–§7.3).
        Route::get('{doctor:public_id}/pad', [PadDesignerController::class, 'edit'])->name('pad.edit');
        Route::put('{doctor:public_id}/pad', [PadDesignerController::class, 'update'])->name('pad.update');
        Route::post('{doctor:public_id}/pad/asset', [PadDesignerController::class, 'asset'])->name('pad.asset');
        Route::delete('{doctor:public_id}/pad/asset', [PadDesignerController::class, 'removeAsset'])->name('pad.asset.destroy');
        Route::get('{doctor:public_id}/pad/asset/{kind}', [PadDesignerController::class, 'assetFile'])->whereIn('kind', ['logo', 'signature', 'sample'])->name('pad.asset.show');
        // The sample pad: a tracing underlay for the designer only. Separate from the pad assets because it
        // accepts a PDF and a phone photo's worth of bytes, and because nothing ever prints it.
        Route::post('{doctor:public_id}/pad/sample', [PadDesignerController::class, 'sample'])->name('pad.sample');
        Route::delete('{doctor:public_id}/pad/sample', [PadDesignerController::class, 'removeSample'])->name('pad.sample.destroy');
        Route::post('{doctor:public_id}/pad/sample/read', [PadDesignerController::class, 'readSample'])->name('pad.sample.read');
        Route::get('{doctor:public_id}/pad/test-print', [PadDesignerController::class, 'testPrint'])->name('pad.test_print');
    });

    Route::prefix('holidays')->name('holidays.')->group(function (): void {
        Route::get('/', [HolidayController::class, 'index'])->name('index');
        Route::post('/', [HolidayController::class, 'store'])->name('store');
        Route::delete('{holiday}', [HolidayController::class, 'destroy'])->whereNumber('holiday')->name('destroy');
    });

    Route::prefix('leaves')->name('leaves.')->group(function (): void {
        Route::get('/', [DoctorLeaveController::class, 'index'])->name('index');
        Route::post('/', [DoctorLeaveController::class, 'store'])->name('store');
        Route::delete('{leave}', [DoctorLeaveController::class, 'destroy'])->whereNumber('leave')->name('destroy');
    });

    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::put('branding', [SettingsController::class, 'branding'])->name('branding.update');
});
