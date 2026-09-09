<?php

declare(strict_types=1);

use App\Http\Controllers\Panel\Prescription\AdviceSnippetController;
use App\Http\Controllers\Panel\Prescription\AiController;
use App\Http\Controllers\Panel\Prescription\AttachmentController;
use App\Http\Controllers\Panel\Prescription\AttachmentFileController;
use App\Http\Controllers\Panel\Prescription\CheckController;
use App\Http\Controllers\Panel\Prescription\DraftController;
use App\Http\Controllers\Panel\Prescription\ExternalCentreController;
use App\Http\Controllers\Panel\Prescription\FavouriteController;
use App\Http\Controllers\Panel\Prescription\InvestigationCatalogController;
use App\Http\Controllers\Panel\Prescription\IssueController;
use App\Http\Controllers\Panel\Prescription\PrescriptionController;
use App\Http\Controllers\Panel\Prescription\PrescriptionIndexController;
use App\Http\Controllers\Panel\Prescription\PrintController;
use App\Http\Controllers\Panel\Prescription\SearchController;
use App\Http\Controllers\Panel\Prescription\ShorthandHelpController;
use App\Http\Controllers\Panel\Prescription\TemplateController;
use App\Http\Controllers\Panel\Prescription\VisitController;
use App\Http\Controllers\Panel\Prescription\VitalsController;
use App\Http\Controllers\Panel\Prescription\WriterController;
use Illuminate\Support\Facades\Route;

// Prescription module, panel surface (names panel.prescription.*). {visit} / {prescription} bind by public_id
// (CONVENTIONS §5); templates, snippets, favourites, vitals, catalog rows bind their bigint id (panel URLs only).
// The writer's XHR endpoints return JSON from the panel surface — the documented exception of CONVENTIONS §5.

// The sidebar's list. Named `panel.prescriptions.index` outside the module's `prescription.` group on purpose: the
// shell's nav entry has pointed at that name (and matched `panel.prescription*` for its selected state) since it
// shipped, and the name reads as the resource it lists.
Route::get('prescriptions', PrescriptionIndexController::class)->name('prescriptions.index');

Route::name('prescription.')->group(function (): void {
    // --- visits -------------------------------------------------------------------------------------------------
    Route::post('serials/{serial:public_id}/visit', [VisitController::class, 'start'])->name('visits.start');
    Route::post('patients/{patient:public_id}/visits', [VisitController::class, 'store'])->name('visits.store');
    Route::get('visits/{visit:public_id}', [VisitController::class, 'show'])->name('visits.show');
    Route::patch('visits/{visit:public_id}', [VisitController::class, 'update'])->name('visits.update');
    Route::post('visits/{visit:public_id}/close', [VisitController::class, 'close'])->name('visits.close');
    Route::get('visits/{visit:public_id}/prescribe', [WriterController::class, 'show'])->name('writer');
    Route::get('visits/{visit:public_id}/vitals', [VitalsController::class, 'index'])->name('vitals.index');
    Route::post('visits/{visit:public_id}/vitals', [VitalsController::class, 'store'])->name('vitals.store');
    Route::patch('vitals/{vital}', [VitalsController::class, 'update'])->name('vitals.update');
    Route::post('visits/{visit:public_id}/prescriptions', [DraftController::class, 'store'])->name('drafts.store');
    Route::post('visits/{visit:public_id}/ai/summary', [AiController::class, 'summary'])->name('ai.summary');
    Route::post('visits/{visit:public_id}/ai/differentials', [AiController::class, 'differentials'])->name('ai.differentials');
    Route::patch('ai-suggestions/{suggestion}', [AiController::class, 'decide'])->name('ai.decide');

    // --- prescriptions ------------------------------------------------------------------------------------------
    Route::prefix('prescriptions/{prescription:public_id}')->name('prescriptions.')->group(function (): void {
        Route::get('/', [PrescriptionController::class, 'show'])->name('show');
        Route::get('versions', [PrescriptionController::class, 'versions'])->name('versions');
        Route::patch('draft', [DraftController::class, 'update'])->name('draft');
        Route::delete('/', [DraftController::class, 'destroy'])->name('destroy');
        Route::post('check', CheckController::class)->name('check');
        Route::post('issue', [IssueController::class, 'issue'])->name('issue');
        Route::post('amend', [IssueController::class, 'amend'])->name('amend')->defaults('ability', 'amend');
        Route::post('void', [IssueController::class, 'void'])->name('void')->defaults('ability', 'void');
        Route::post('apply-template/{template}', [TemplateController::class, 'apply'])->name('apply-template');
        Route::post('handwriting', [AttachmentController::class, 'handwriting'])->name('handwriting');
        Route::post('drawing', [AttachmentController::class, 'drawing'])->name('drawing');
        Route::post('send', [AttachmentController::class, 'send'])->name('send');
    });

    // --- output: print · pharmacy · PDF · private attachments (§7.3, §7.5, §7.6) -------------------------------
    // Named panel.prescription.{print,pharmacy,pdf,…} — NOT under the `prescriptions.` sub-group: `print_url` in
    // the issue response resolves `panel.prescription.print`, and the writer opens it in the same click.
    Route::prefix('prescriptions/{prescription:public_id}')->group(function (): void {
        Route::get('print', [PrintController::class, 'print'])->name('print');
        Route::get('pharmacy', [PrintController::class, 'pharmacy'])->name('pharmacy');
        Route::get('pdf', [PrintController::class, 'pdf'])->name('pdf');
        Route::post('pdf/regenerate', [PrintController::class, 'regenerate'])->name('pdf.regenerate');
        Route::get('files/{file}', [AttachmentFileController::class, 'show'])->name('files')->where('file', '[A-Za-z0-9._-]+');
    });

    // --- templates · snippets · clinic catalog · centres -------------------------------------------------------
    Route::get('prescription-templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::post('prescription-templates', [TemplateController::class, 'store'])->name('templates.store');
    Route::get('prescription-templates/{template}', [TemplateController::class, 'show'])->name('templates.show');
    Route::put('prescription-templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
    Route::delete('prescription-templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');

    Route::get('advice-snippets', [AdviceSnippetController::class, 'index'])->name('snippets.index');
    Route::post('advice-snippets', [AdviceSnippetController::class, 'store'])->name('snippets.store');
    Route::put('advice-snippets/{snippet}', [AdviceSnippetController::class, 'update'])->name('snippets.update');
    Route::delete('advice-snippets/{snippet}', [AdviceSnippetController::class, 'destroy'])->name('snippets.destroy');

    Route::get('investigation-catalog', [InvestigationCatalogController::class, 'index'])->name('investigations.index');
    Route::post('investigation-catalog', [InvestigationCatalogController::class, 'store'])->name('investigations.store');
    Route::put('investigation-catalog/{item}', [InvestigationCatalogController::class, 'update'])->name('investigations.update');
    Route::delete('investigation-catalog/{item}', [InvestigationCatalogController::class, 'destroy'])->name('investigations.destroy');

    Route::get('external-diagnostic-centres', [ExternalCentreController::class, 'index'])->name('centres.index');
    Route::post('external-diagnostic-centres', [ExternalCentreController::class, 'store'])->name('centres.store');
    Route::put('external-diagnostic-centres/{centre}', [ExternalCentreController::class, 'update'])->name('centres.update');
    Route::delete('external-diagnostic-centres/{centre}', [ExternalCentreController::class, 'destroy'])->name('centres.destroy');

    // --- doctor learning ----------------------------------------------------------------------------------------
    Route::get('doctors/me/favourites', [FavouriteController::class, 'index'])->name('favourites.index');
    Route::post('doctors/me/favourites', [FavouriteController::class, 'store'])->name('favourites.store');
    Route::patch('doctors/me/favourites/{favourite}', [FavouriteController::class, 'update'])->name('favourites.update');
    Route::delete('doctors/me/favourites/{favourite}', [FavouriteController::class, 'destroy'])->name('favourites.destroy');
    Route::get('doctors/me/top-drugs', [FavouriteController::class, 'topDrugs'])->name('favourites.top-drugs');

    // --- search (throttle:prescription-search, 20 req/s per user, §3.8) -----------------------------------------
    Route::middleware('throttle:prescription-search')->prefix('search')->name('search.')->group(function (): void {
        Route::get('drugs', [SearchController::class, 'drugs'])->name('drugs');
        Route::get('icd', [SearchController::class, 'icd'])->name('icd');
        Route::get('investigations', [InvestigationCatalogController::class, 'index'])->name('investigations');
        Route::get('doctors', [SearchController::class, 'doctors'])->name('doctors');
    });

    Route::get('help/shorthand', ShorthandHelpController::class)->name('help.shorthand');
});
