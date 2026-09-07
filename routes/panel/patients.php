<?php

declare(strict_types=1);

use App\Http\Controllers\Panel\Patients\PatientAllergyController;
use App\Http\Controllers\Panel\Patients\PatientConditionController;
use App\Http\Controllers\Panel\Patients\PatientConsentController;
use App\Http\Controllers\Panel\Patients\PatientController;
use App\Http\Controllers\Panel\Patients\PatientDependentController;
use App\Http\Controllers\Panel\Patients\PatientDocumentController;
use App\Http\Controllers\Panel\Patients\PatientMedicationController;
use App\Http\Controllers\Panel\Patients\PatientMergeController;
use App\Http\Controllers\Panel\Patients\PatientTimelineController;
use App\Http\Controllers\Panel\Patients\PatientVitalsTrendController;
use Illuminate\Support\Facades\Route;

// Patients module, panel surface (names panel.patients.*). {patient} binds public_id; child rows bind their bigint
// id scoped to the patient (CONVENTIONS §5). The timeline / vitals-trend / clinical-list endpoints return JSON
// for the prescription writer's XHR (PRESCRIPTION.md §8); the mutations answer JSON or an Inertia redirect.
Route::prefix('patients')->name('patients.')->scopeBindings()->group(function (): void {
    Route::get('/', [PatientController::class, 'index'])->name('index');
    Route::get('create', [PatientController::class, 'create'])->name('create');
    Route::post('/', [PatientController::class, 'store'])->name('store');
    Route::get('{patient:public_id}', [PatientController::class, 'show'])->name('show');
    Route::get('{patient:public_id}/edit', [PatientController::class, 'edit'])->name('edit');
    Route::put('{patient:public_id}', [PatientController::class, 'update'])->name('update');

    Route::post('{patient:public_id}/dependents', [PatientDependentController::class, 'store'])->name('dependents.store');
    Route::post('{patient:public_id}/merge', PatientMergeController::class)->name('merge');

    Route::get('{patient:public_id}/timeline', PatientTimelineController::class)->name('timeline');
    Route::get('{patient:public_id}/vitals-trend', PatientVitalsTrendController::class)->name('vitals_trend');

    Route::get('{patient:public_id}/allergies', [PatientAllergyController::class, 'index'])->name('allergies.index');
    Route::post('{patient:public_id}/allergies', [PatientAllergyController::class, 'store'])->name('allergies.store');
    Route::patch('{patient:public_id}/allergies/{allergy}', [PatientAllergyController::class, 'update'])->name('allergies.update');
    Route::delete('{patient:public_id}/allergies/{allergy}', [PatientAllergyController::class, 'destroy'])->name('allergies.destroy');

    Route::get('{patient:public_id}/conditions', [PatientConditionController::class, 'index'])->name('conditions.index');
    Route::post('{patient:public_id}/conditions', [PatientConditionController::class, 'store'])->name('conditions.store');
    Route::patch('{patient:public_id}/conditions/{condition}', [PatientConditionController::class, 'update'])->name('conditions.update');
    Route::delete('{patient:public_id}/conditions/{condition}', [PatientConditionController::class, 'destroy'])->name('conditions.destroy');

    Route::get('{patient:public_id}/medications', [PatientMedicationController::class, 'index'])->name('medications.index');
    Route::post('{patient:public_id}/medications', [PatientMedicationController::class, 'store'])->name('medications.store');
    Route::patch('{patient:public_id}/medications/{medication}', [PatientMedicationController::class, 'update'])->name('medications.update');
    Route::delete('{patient:public_id}/medications/{medication}', [PatientMedicationController::class, 'destroy'])->name('medications.destroy');

    Route::post('{patient:public_id}/documents', [PatientDocumentController::class, 'store'])->name('documents.store');
    Route::get('{patient:public_id}/documents/{document}', [PatientDocumentController::class, 'show'])->name('documents.show');

    Route::post('{patient:public_id}/consents', [PatientConsentController::class, 'store'])->name('consents.store');
});
