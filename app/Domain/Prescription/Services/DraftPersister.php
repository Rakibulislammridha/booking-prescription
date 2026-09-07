<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

use App\Domain\Prescription\Data\DoseJson;
use App\Domain\Prescription\Data\ResolvedItem;
use App\Domain\Prescription\Safety\SafetyAlert;
use App\Domain\Prescription\Safety\SafetyReport;
use App\Domain\Prescription\Shorthand\Keywords;
use App\Domain\Shared\Actor;
use App\Models\Tenant\AdviceSnippet;
use App\Models\Tenant\ExternalDiagnosticCentre;
use App\Models\Tenant\InvestigationCatalogItem;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionAdvice;
use App\Models\Tenant\PrescriptionInvestigation;
use App\Models\Tenant\PrescriptionItem;
use App\Models\Tenant\PrescriptionReferral;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Writes the draft's child rows by `key` (rows with an id are updated, the rest inserted, absent ids deleted —
 * PRESCRIPTION.md §4.13) through Eloquent save()/delete() (model guards + triggers apply). sort_order is unique per
 * prescription, so reorders go through a two-phase offset. Returns id → client key maps for the response.
 */
final class DraftPersister
{
    private const OFFSET = 1000;

    public function __construct(private readonly PrescriptionAuditor $auditor) {}

    /**
     * @param  list<ResolvedItem>  $items
     * @return array<int, string> id → key
     */
    public function items(Prescription $rx, array $items, SafetyReport $report, Actor $actor, string $language): array
    {
        $existing = $rx->items->keyBy('id');
        $keep = [];
        $keys = [];

        foreach ($items as $item) {
            $keep[] = $item->id;
        }

        foreach ($existing as $row) {
            if (! in_array($row->id, $keep, true)) {
                $row->delete();
            }
        }

        $this->offsetSortOrders($existing->filter(fn (PrescriptionItem $r) => in_array($r->id, $keep, true))->all());

        foreach ($items as $i => $item) {
            $row = $item->id !== null ? $existing->get($item->id) : null;
            $row ??= new PrescriptionItem(['prescription_id' => $rx->id]);
            $parsed = $item->parsed;
            $drug = $item->drug;
            $lang = $language === 'bn' ? 'bn' : 'en';
            $newOverrides = [];

            $attributes = ($drug?->snapshotColumns() ?? ['generic_id' => null, 'brand_id' => null, 'strength_id' => null, 'custom_brand_id' => null, 'generic_name' => '', 'brand_name' => null, 'strength' => null, 'form' => null, 'route' => null, 'info_url_slug' => null]) + [
                'sort_order' => $i,
                'route' => $parsed->routeCode !== null ? self::routeName($parsed->routeCode) : $drug?->route,
                'dose_schedule' => DoseJson::doseSchedule($parsed),
                'dose_json' => $parsed->toArray(),
                'duration_days' => DoseJson::durationDays($parsed),
                'duration_text' => DoseJson::durationText($parsed, $lang),
                'quantity' => DoseJson::quantity($parsed),
                'quantity_unit' => DoseJson::quantityUnit($parsed),
                'timing' => $parsed->timing,
                'instruction' => $parsed->instruction,
                'instruction_bn' => $item->instructionBn ?? ($parsed->instruction !== null && self::isBangla($parsed->instruction) ? $parsed->instruction : null),
                'is_continued' => DoseJson::isContinued($parsed),
                'safety_overrides' => $this->overridesFor($item, $report, $actor, $newOverrides),
            ];
            $attributes['generic_name'] = $attributes['generic_name'] !== '' ? $attributes['generic_name'] : ($row->generic_name ?? '');
            $attributes['sort_order'] = $i;
            $row->fill($attributes);
            $row->save();
            $keys[$row->id] = $item->key;

            foreach ($newOverrides as [$alert, $reason]) {
                $this->auditor->safetyOverride($row, $alert->toArray(), $reason);
            }
        }

        return $keys;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, string>
     */
    public function investigations(Prescription $rx, array $rows): array
    {
        return $this->syncSorted($rx->investigations->keyBy('id'), $rows, fn () => new PrescriptionInvestigation(['prescription_id' => $rx->id]), function (PrescriptionInvestigation|PrescriptionAdvice $row, array $data, int $i): void {
            $catalog = isset($data['investigation_catalog_id']) ? InvestigationCatalogItem::query()->find((int) $data['investigation_catalog_id']) : null;
            $centre = isset($data['external_diagnostic_centre_id']) ? ExternalDiagnosticCentre::query()->find((int) $data['external_diagnostic_centre_id']) : null;
            $row->fill([
                'sort_order' => $i,
                'investigation_catalog_id' => $catalog?->id,
                'name' => $catalog !== null ? $catalog->name : (string) ($data['name'] ?? $row->name ?? ''),
                'name_bn' => $catalog !== null ? $catalog->name_bn : ($data['name_bn'] ?? null),
                'price_paisa' => $catalog !== null ? $catalog->price_paisa : (isset($data['price_paisa']) ? (int) $data['price_paisa'] : null),
                'external_diagnostic_centre_id' => $centre?->id,
                'referral_note' => $data['referral_note'] ?? null,
                'is_urgent' => (bool) ($data['is_urgent'] ?? false),
            ]);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, string>
     */
    public function advice(Prescription $rx, array $rows): array
    {
        return $this->syncSorted($rx->advice->keyBy('id'), $rows, fn () => new PrescriptionAdvice(['prescription_id' => $rx->id]), function (PrescriptionInvestigation|PrescriptionAdvice $row, array $data, int $i): void {
            $snippet = isset($data['advice_snippet_id']) ? AdviceSnippet::query()->find((int) $data['advice_snippet_id']) : null;
            $text = isset($data['text']) && trim((string) $data['text']) !== '' ? trim((string) $data['text']) : ($snippet !== null ? $snippet->text : ($row->text ?? ''));
            $row->fill([
                'sort_order' => $i, 'advice_snippet_id' => $snippet?->id, 'text' => $text,
                'text_bn' => isset($data['text_bn']) && trim((string) $data['text_bn']) !== '' ? trim((string) $data['text_bn']) : $snippet?->text_bn,
            ]);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, string>
     */
    public function referrals(Prescription $rx, array $rows): array
    {
        $existing = $rx->referrals->keyBy('id');
        $keep = array_values(array_filter(array_map(fn ($r) => isset($r['id']) ? (int) $r['id'] : null, $rows)));

        foreach ($existing as $row) {
            if (! in_array($row->id, $keep, true)) {
                $row->delete();
            }
        }

        $keys = [];

        foreach ($rows as $i => $data) {
            $row = isset($data['id']) ? $existing->get((int) $data['id']) : null;
            $row ??= new PrescriptionReferral(['prescription_id' => $rx->id]);
            $centre = isset($data['external_diagnostic_centre_id']) ? ExternalDiagnosticCentre::query()->find((int) $data['external_diagnostic_centre_id']) : null;
            $row->fill([
                'type' => (string) ($data['type'] ?? 'doctor'),
                'referred_to_doctor_id' => isset($data['referred_to_doctor_id']) ? (int) $data['referred_to_doctor_id'] : null,
                'external_diagnostic_centre_id' => $centre?->id,
                'referred_to_name' => (string) ($data['referred_to_name'] ?? ($centre !== null ? $centre->name : '')),
                'referred_to_specialty' => $data['referred_to_specialty'] ?? null,
                'note' => $data['note'] ?? null,
                'is_urgent' => (bool) ($data['is_urgent'] ?? false),
            ]);
            $row->save();
            $keys[$row->id] = (string) ($data['key'] ?? 'r'.$row->id);
        }

        return $keys;
    }

    /**
     * @template TRow of PrescriptionInvestigation|PrescriptionAdvice
     *
     * @param  Collection<array-key, TRow>  $existing
     * @param  list<array<string, mixed>>  $rows
     * @param  \Closure(): TRow  $make
     * @param  \Closure(TRow, array<string, mixed>, int): void  $fill
     * @return array<int, string>
     */
    private function syncSorted(Collection $existing, array $rows, \Closure $make, \Closure $fill): array
    {
        $keep = array_values(array_filter(array_map(fn ($r) => isset($r['id']) ? (int) $r['id'] : null, $rows)));

        foreach ($existing as $row) {
            if (! in_array($row->getKey(), $keep, true)) {
                $row->delete();
            }
        }

        $this->offsetSortOrders($existing->filter(fn (Model $r) => in_array($r->getKey(), $keep, true))->all());
        $keys = [];

        foreach ($rows as $i => $data) {
            $row = isset($data['id']) ? $existing->get((int) $data['id']) : null;
            $row ??= $make();
            $fill($row, $data, $i);
            $row->save();
            $keys[(int) $row->getKey()] = (string) ($data['key'] ?? $row->getKey());
        }

        return $keys;
    }

    /**
     * Two-phase reorder: move kept rows out of the way so the (prescription_id, sort_order) unique never collides.
     *
     * @param  array<int, Model>  $rows
     */
    private function offsetSortOrders(array $rows): void
    {
        foreach ($rows as $row) {
            $row->forceFill(['sort_order' => (int) $row->getAttribute('sort_order') + self::OFFSET])->save();
        }
    }

    /**
     * Stored overrides for a line: requested fingerprints that match a current alert naming the line get
     * kind/severity/by/at filled (new ones audited); stored ones whose fingerprint vanished are dropped (§5.2).
     *
     * @param  list<array{0: SafetyAlert, 1: string}>  $newOverrides  filled with the overrides created by this call (audited after the row is saved)
     * @return list<array<string, mixed>>
     */
    private function overridesFor(ResolvedItem $item, SafetyReport $report, Actor $actor, array &$newOverrides): array
    {
        $alerts = [];

        foreach ($report->alerts as $alert) {
            if (in_array($item->key, $alert->itemKeys, true)) {
                $alerts[$alert->fingerprint] = $alert;
            }
        }

        $stored = [];

        foreach ($item->existingOverrides as $o) {
            if (isset($o['fingerprint'], $alerts[$o['fingerprint']])) {
                $stored[$o['fingerprint']] = $o;
            }
        }

        foreach ($item->overrideRequests as $req) {
            $alert = $alerts[$req['fingerprint']] ?? null;

            if ($alert === null || ! $alert->overridable || isset($stored[$req['fingerprint']]) || mb_strlen(trim($req['reason'])) < 10) {
                continue;
            }

            $stored[$req['fingerprint']] = [
                'fingerprint' => $alert->fingerprint, 'kind' => $alert->kind(), 'severity' => $alert->severity->value, 'reason' => trim($req['reason']),
                'overridden_by_user_id' => $actor->userId, 'overridden_at' => now()->toIso8601String(),
            ];

            $newOverrides[] = [$alert, trim($req['reason'])];
        }

        return array_values($stored);
    }

    public static function routeName(string $code): string
    {
        $labels = Keywords::labels()['routes'][$code]['en'] ?? $code;

        return ucfirst((string) $labels);
    }

    public static function isBangla(string $text): bool
    {
        return preg_match('/\p{Bengali}/u', $text) === 1;
    }

    /**
     * The alerts that name an item, as the stored `safety_overrides[].kind` vocabulary requires.
     *
     * @return list<SafetyAlert>
     */
    public static function alertsFor(SafetyReport $report, string $key): array
    {
        return $report->forItem($key);
    }
}
