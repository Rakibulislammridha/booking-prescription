<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Catalog;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Catalog\Actions\ToggleCatalogRowActive;
use App\Domain\Catalog\Actions\UpdateDrugInformation;
use App\Domain\Catalog\Actions\UpdateIcd10Aliases;
use App\Domain\Catalog\Queries\CatalogBrowser;
use App\Domain\SaaS\Services\CentralAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Catalog\ToggleActiveRequest;
use App\Http\Requests\Super\Catalog\UpdateDrugInformationRequest;
use App\Http\Requests\Super\Catalog\UpdateIcd10AliasesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The catalogue browser (BRIEF §3.2): six tabs over the shared clinical reference, server-side search, a
 * read-only drawer, and the three edits that are safe to make centrally by hand — an active switch, the
 * patient-facing drug information, an ICD-10 code's aliases. Molecules, brands and strengths are never
 * restructured from a screen: that is what the versioned import is for. Every write goes through an Action
 * inside CatalogWriteContext, lands in `audit_logs_central`, and re-upserts its search document.
 */
final class BrowserController extends Controller
{
    public const KINDS = CatalogBrowser::TABS;

    public function index(Request $request, CatalogBrowser $browser): Response
    {
        $validated = $request->validate([
            'tab' => ['nullable', Rule::in(self::KINDS)],
            'q' => ['nullable', 'string', 'max:120'],
            'active' => ['nullable', Rule::in(['all', 'active', 'inactive'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $tab = (string) ($validated['tab'] ?? 'generics');
        $q = (string) ($validated['q'] ?? '');
        $active = (string) ($validated['active'] ?? 'all');
        $page = (int) ($validated['page'] ?? 1);
        $list = $browser->list($tab, $q, $active, $page);

        return Inertia::render('Super/Catalog/Browse', [
            'tab' => $tab,
            'tabs' => self::KINDS,
            'counts' => $browser->counts(),
            'filters' => ['q' => $q, 'active' => $active],
            'rows' => $list['rows'],
            'meta' => $list['meta'],
            'engine' => $list['engine'],
        ]);
    }

    public function show(string $kind, int $id, CatalogBrowser $browser): JsonResponse
    {
        $detail = $browser->detail($kind, $id);

        abort_if($detail === null, 404);

        return response()->json($detail);
    }

    public function active(ToggleActiveRequest $request, string $kind, int $id, ToggleCatalogRowActive $toggle, CentralAudit $audit): RedirectResponse
    {
        $result = $toggle->handle($kind, $id, $request->boolean('active'));
        $audit->record(CentralAuditAction::Update, null, $result['model'], $result['before'], $result['after']);

        return back()->with('flash.success', __($request->boolean('active') ? 'super.catalog.flash.activated' : 'super.catalog.flash.deactivated'));
    }

    public function information(UpdateDrugInformationRequest $request, int $id, UpdateDrugInformation $update, CentralAudit $audit): RedirectResponse
    {
        $slug = (string) $request->validated('public_slug');
        $result = $update->handle($id, $request->text(), $slug !== '' ? $slug : null, $request->boolean('published'));

        if ($result['after'] !== [] || $result['before'] !== []) {
            $audit->record(CentralAuditAction::Update, null, $result['model'], $result['before'], $result['after']);
        }

        return back()->with('flash.success', __('super.catalog.flash.information_saved'));
    }

    public function aliases(UpdateIcd10AliasesRequest $request, int $id, UpdateIcd10Aliases $update, CentralAudit $audit): RedirectResponse
    {
        $titleBn = $request->validated('title_bn');
        $result = $update->handle($id, $request->aliases(), is_string($titleBn) ? $titleBn : ($request->has('title_bn') ? '' : null));
        $audit->record(CentralAuditAction::Update, null, $result['model'], $result['before'], $result['after']);

        return back()->with('flash.success', __('super.catalog.flash.aliases_saved'));
    }
}
