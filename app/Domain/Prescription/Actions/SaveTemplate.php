<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Data\DoseJson;
use App\Domain\Prescription\Data\TemplateData;
use App\Domain\Prescription\Exceptions\TemplateNameTaken;
use App\Domain\Prescription\Services\ItemResolver;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionTemplate;
use App\Models\Tenant\PrescriptionTemplateItem;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Create / full-replace a template (PRESCRIPTION.md §3.6). With `from_prescription_id` the current draft/issued body
 * is captured: items with their dose_json, investigations, advice, follow-up interval, complaints/findings.
 */
final class SaveTemplate
{
    public function __construct(private readonly ItemResolver $items) {}

    public function handle(TemplateData $data, ?Doctor $owner, Actor $actor, ?PrescriptionTemplate $existing = null): PrescriptionTemplate
    {
        return DB::transaction(function () use ($data, $owner, $actor, $existing): PrescriptionTemplate {
            $ownerId = $existing !== null ? $existing->doctor_id : $owner?->id;
            $clash = PrescriptionTemplate::query()->where('doctor_id', $ownerId)->whereRaw('lower(name) = ?', [mb_strtolower($data->name)])
                ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->id))->exists();

            if ($clash) {
                throw new TemplateNameTaken($data->name);
            }

            $source = $data->fromPrescriptionId !== null ? Prescription::query()->wherePublicId($data->fromPrescriptionId)->with(['items', 'investigations', 'advice', 'visit'])->first() : null;
            $body = $data->body ?? [];

            if ($source !== null) {
                $visit = $source->visit;
                $body += [
                    'chief_complaints' => $data->includeClinical ? array_values($visit->chief_complaints) : [],
                    'examination_findings' => $data->includeClinical ? $visit->examination_findings : null,
                    'advice' => $source->advice->map(fn ($a) => ['snippet_id' => $a->advice_snippet_id, 'text' => $a->text, 'text_bn' => $a->text_bn])->values()->all(),
                    'investigations' => $source->investigations->map(fn ($x) => ['investigation_catalog_id' => $x->investigation_catalog_id, 'name' => $x->name])->values()->all(),
                    'follow_up_days' => $visit->follow_up_on !== null ? max(0, (int) Clock::today()->diffInDays($visit->follow_up_on, false)) : null,
                ];
            }

            $template = $existing ?? new PrescriptionTemplate;
            $template->fill([
                'doctor_id' => $ownerId, 'name' => $data->name, 'shorthand' => $data->shorthand,
                'icd10_code' => $data->icd10Code ?? ($source?->visit->primaryDiagnosisCode()), 'diagnosis_title' => $data->diagnosisTitle ?? self::diagnosisTitle($source),
                'is_shared' => $data->isShared, 'body' => $body + ['chief_complaints' => [], 'examination_findings' => null, 'advice' => [], 'investigations' => [], 'follow_up_days' => null],
                'created_by_user_id' => $template->created_by_user_id ?? $actor->userId,
            ]);
            $template->save();

            if ($source !== null) {
                $template->items()->delete();

                foreach ($source->items as $item) {
                    $copy = new PrescriptionTemplateItem(array_intersect_key($item->getAttributes(), array_flip((new PrescriptionTemplateItem)->getFillable())));
                    $copy->prescription_template_id = $template->id;
                    $copy->dose_json = $item->dose_json;
                    $copy->save();
                }
            } elseif ($data->items !== null) {
                $template->items()->delete();
                $resolved = $this->items->resolve($data->items, [], (int) config('prescription.cont_days', 30), 'en');

                foreach ($resolved as $i => $r) {
                    $parsed = $r->parsed;
                    $row = new PrescriptionTemplateItem(($r->drug?->snapshotColumns() ?? []) + [
                        'sort_order' => $i, 'generic_name' => $r->drug !== null ? $r->drug->genericName : '', 'dose_schedule' => DoseJson::doseSchedule($parsed), 'dose_json' => $parsed->toArray(),
                        'duration_days' => DoseJson::durationDays($parsed), 'duration_text' => DoseJson::durationText($parsed), 'quantity' => DoseJson::quantity($parsed),
                        'quantity_unit' => DoseJson::quantityUnit($parsed), 'timing' => $parsed->timing, 'instruction' => $parsed->instruction, 'is_continued' => DoseJson::isContinued($parsed),
                    ]);
                    unset($row->info_url_slug);
                    $row->prescription_template_id = $template->id;
                    $row->save();
                }
            }

            return $template->refresh();
        });
    }

    private static function diagnosisTitle(?Prescription $source): ?string
    {
        if ($source === null) {
            return null;
        }

        $code = $source->visit->primaryDiagnosisCode();

        foreach ($source->visit->diagnoses as $dx) {
            if (($dx['icd10_code'] ?? null) === $code) {
                return isset($dx['title']) ? (string) $dx['title'] : null;
            }
        }

        return null;
    }
}
