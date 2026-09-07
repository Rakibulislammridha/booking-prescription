<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Prescription\Actions\DeleteAdviceSnippet;
use App\Domain\Prescription\Actions\SaveAdviceSnippet;
use App\Domain\Prescription\Services\WriterPayloadBuilder;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Prescription\SaveAdviceSnippetRequest;
use App\Models\Tenant\AdviceSnippet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET/POST/PUT/DELETE /panel/advice-snippets[/{snippet}] (PRESCRIPTION.md §4.7); GET ?q=&category= is the Ctrl+K palette read. */
final class AdviceSnippetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $doctorId = (int) ($request->user('web')->doctor()->value('id') ?? 0);
        $q = trim((string) $request->query('q', ''));
        $rows = AdviceSnippet::query()->active()->visibleTo($doctorId)
            ->when($request->filled('category'), fn ($b) => $b->where('category', (string) $request->query('category')))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('text', 'ILIKE', "%{$q}%")->orWhere('text_bn', 'ILIKE', "%{$q}%")->orWhere('shorthand', 'ILIKE', '%'.ltrim($q, '/').'%')))
            ->orderByDesc('use_count')->orderBy('id')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (AdviceSnippet $s) => WriterPayloadBuilder::snippetRow($s))->values()->all()])->header('Cache-Control', 'private, max-age=30');
    }

    public function store(SaveAdviceSnippetRequest $request, SaveAdviceSnippet $save): JsonResponse
    {
        $snippet = $save->handle($request->validated(), $request->user('web')->doctor, Actor::fromRequest($request));

        return response()->json(['data' => WriterPayloadBuilder::snippetRow($snippet)], 201);
    }

    public function update(SaveAdviceSnippetRequest $request, AdviceSnippet $snippet, SaveAdviceSnippet $save): JsonResponse
    {
        $snippet = $save->handle($request->validated(), $request->user('web')->doctor, Actor::fromRequest($request), $snippet);

        return response()->json(['data' => WriterPayloadBuilder::snippetRow($snippet)]);
    }

    public function destroy(Request $request, AdviceSnippet $snippet, DeleteAdviceSnippet $delete): JsonResponse
    {
        $this->authorize('delete', $snippet);
        $delete->handle($snippet, Actor::fromRequest($request));

        return response()->json(['deleted' => true]);
    }
}
