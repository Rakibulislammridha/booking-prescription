<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Clinic\Services\ActiveBranch;
use App\Domain\Prescription\Actions\DeleteInvestigationCatalogItem;
use App\Domain\Prescription\Actions\SaveInvestigationCatalogItem;
use App\Domain\Prescription\Services\WriterPayloadBuilder;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Prescription\SaveInvestigationCatalogItemRequest;
use App\Models\Tenant\InvestigationCatalogItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /panel/investigation-catalog CRUD + GET /panel/search/investigations?q (Postgres ILIKE, §3.8). */
final class InvestigationCatalogController extends Controller
{
    public function index(Request $request, ActiveBranch $branch): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $rows = InvestigationCatalogItem::query()->forBranch($branch->current()?->id)
            ->when(! $request->boolean('include_inactive'), fn ($b) => $b->active())
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('name', 'ILIKE', "%{$q}%")->orWhere('name_bn', 'ILIKE', "%{$q}%")->orWhere('code', 'ILIKE', "{$q}%")))
            ->orderBy('sort_order')->orderBy('name')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (InvestigationCatalogItem $i) => WriterPayloadBuilder::investigationRow($i))->values()->all()])->header('Cache-Control', 'private, max-age=30');
    }

    public function store(SaveInvestigationCatalogItemRequest $request, SaveInvestigationCatalogItem $save): JsonResponse
    {
        return response()->json(['data' => WriterPayloadBuilder::investigationRow($save->handle($request->validated(), Actor::fromRequest($request)))], 201);
    }

    public function update(SaveInvestigationCatalogItemRequest $request, InvestigationCatalogItem $item, SaveInvestigationCatalogItem $save): JsonResponse
    {
        return response()->json(['data' => WriterPayloadBuilder::investigationRow($save->handle($request->validated(), Actor::fromRequest($request), $item))]);
    }

    public function destroy(Request $request, InvestigationCatalogItem $item, DeleteInvestigationCatalogItem $delete): JsonResponse
    {
        abort_unless($request->user('web')->can('prescriptions.write'), 403);
        $delete->handle($item, Actor::fromRequest($request));

        return response()->json(['deleted' => true]);
    }
}
