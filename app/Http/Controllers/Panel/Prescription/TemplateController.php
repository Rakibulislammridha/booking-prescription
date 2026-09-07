<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Prescription\Actions\ApplyTemplate;
use App\Domain\Prescription\Actions\DeleteTemplate;
use App\Domain\Prescription\Actions\SaveTemplate;
use App\Domain\Prescription\Services\WriterPayloadBuilder;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Prescription\ApplyTemplateRequest;
use App\Http\Requests\Panel\Prescription\SaveTemplateRequest;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionTemplate;
use App\Models\Tenant\PrescriptionTemplateItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /panel/prescription-templates CRUD + apply (PRESCRIPTION.md §3.6). */
final class TemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $doctorId = (int) ($request->user('web')->doctor()->value('id') ?? 0);
        $rows = PrescriptionTemplate::query()->visibleTo($doctorId)->withCount('items')->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn (PrescriptionTemplate $t) => WriterPayloadBuilder::templateBrief($t))->values()->all()]);
    }

    public function show(PrescriptionTemplate $template): JsonResponse
    {
        $this->authorize('view', $template);
        $template->load('items');

        return response()->json(['data' => self::full($template)]);
    }

    public function store(SaveTemplateRequest $request, SaveTemplate $save): JsonResponse
    {
        $template = $save->handle($request->toData(), $request->user('web')->doctor, Actor::fromRequest($request));

        return response()->json(['data' => self::full($template->load('items'))], 201);
    }

    public function update(SaveTemplateRequest $request, PrescriptionTemplate $template, SaveTemplate $save): JsonResponse
    {
        $template = $save->handle($request->toData(), $request->user('web')->doctor, Actor::fromRequest($request), $template);

        return response()->json(['data' => self::full($template->load('items'))]);
    }

    public function destroy(Request $request, PrescriptionTemplate $template, DeleteTemplate $delete): JsonResponse
    {
        $this->authorize('delete', $template);
        $delete->handle($template, Actor::fromRequest($request));

        return response()->json(['deleted' => true]);
    }

    public function apply(ApplyTemplateRequest $request, Prescription $prescription, PrescriptionTemplate $template, ApplyTemplate $apply): JsonResponse
    {
        return response()->json($apply->handle($prescription, $template, (string) $request->validated('mode', 'append'), Actor::fromRequest($request))->toArray());
    }

    /** @return array<string, mixed> */
    public static function full(PrescriptionTemplate $t): array
    {
        return WriterPayloadBuilder::templateBrief($t) + [
            'body' => $t->body,
            'items' => $t->items->map(fn (PrescriptionTemplateItem $i) => [
                'id' => $i->id, 'sort_order' => $i->sort_order,
                'drug' => ['generic_id' => $i->generic_id, 'brand_id' => $i->brand_id, 'custom_brand_id' => $i->custom_brand_id, 'strength_id' => $i->strength_id, 'generic_name' => $i->generic_name, 'brand_name' => $i->brand_name, 'strength' => $i->strength, 'form' => $i->form, 'route' => $i->route],
                'shorthand' => (string) ($i->dose_json['normalized'] ?? ''), 'dose_json' => $i->dose_json, 'dose_schedule' => $i->dose_schedule, 'duration_days' => $i->duration_days,
                'quantity' => $i->quantity, 'quantity_unit' => $i->quantity_unit, 'timing' => $i->timing->value, 'instruction' => $i->instruction, 'instruction_bn' => $i->instruction_bn, 'is_continued' => $i->is_continued,
            ])->values()->all(),
        ];
    }
}
