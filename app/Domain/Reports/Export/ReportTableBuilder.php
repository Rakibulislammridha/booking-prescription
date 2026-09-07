<?php

declare(strict_types=1);

namespace App\Domain\Reports\Export;

use App\Domain\Billing\Services\Paisa;
use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Data\ReportTable;
use App\Domain\Reports\Enums\ReportKind;
use Generator;

/**
 * Flattens a report payload into the one table the three writers share.
 *
 * It is handed the payload the PAGE was rendered from — never a fresh query — so a CSV cannot disagree with the
 * screen it was downloaded from. Money is written as a plain decimal string (`1250.00`), not `৳1,250.00`: a
 * spreadsheet must be able to sum the column, and the currency belongs in the header.
 *
 * Rows come out of a `Generator`, so `CsvWriter` and `XlsxWriter` stream them; only `PdfWriter` materialises,
 * and only up to its own page limit.
 */
final class ReportTableBuilder
{
    /** @param array<string, mixed> $data */
    public function build(ReportKind $kind, array $data, ReportFilters $filters): ReportTable
    {
        $subtitle = __('reports.export.range', ['from' => $filters->fromDate(), 'to' => $filters->toDate()]);

        return match ($kind) {
            ReportKind::Appointments => $this->appointments($data, $subtitle),
            ReportKind::WaitTimes => $this->waitTimes($data, $subtitle),
            ReportKind::Revenue => $this->revenue($data, $subtitle),
            ReportKind::Patients => $this->patients($data, $subtitle),
            ReportKind::Clinical => $this->clinical($data, $subtitle),
            ReportKind::PeakHours => $this->peakHours($data, $subtitle),
            ReportKind::Dashboard => $this->dashboard($data, $subtitle),
        };
    }

    /** @param array<string, mixed> $data */
    private function appointments(array $data, string $subtitle): ReportTable
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($data['by_doctor'] ?? null) ? $data['by_doctor'] : [];

        return new ReportTable(
            title: __('reports.appointments.title'),
            subtitle: $subtitle,
            columns: [
                __('reports.column.doctor'), __('reports.column.booked'), __('reports.column.completed'),
                __('reports.column.no_show'), __('reports.column.cancelled'), __('reports.column.expected'),
                __('reports.column.no_show_rate'), __('reports.column.completion_rate'),
            ],
            rows: function () use ($rows): Generator {
                foreach ($rows as $row) {
                    yield [
                        self::text($row['doctor_name'] ?? null),
                        self::int($row['booked'] ?? null),
                        self::int($row['completed'] ?? null),
                        self::int($row['no_show'] ?? null),
                        self::int($row['cancelled'] ?? null),
                        self::int($row['expected'] ?? null),
                        self::percent($row['no_show_rate'] ?? null),
                        self::percent($row['completion_rate'] ?? null),
                    ];
                }
            },
            align: [1 => 'right', 2 => 'right', 3 => 'right', 4 => 'right', 5 => 'right', 6 => 'right', 7 => 'right'],
            notes: [__('reports.note.no_show'), __('reports.note.no_show_rate'), __('reports.note.transfer')],
            knownRowCount: count($rows),
        );
    }

    /** @param array<string, mixed> $data */
    private function waitTimes(array $data, string $subtitle): ReportTable
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($data['by_doctor'] ?? null) ? $data['by_doctor'] : [];
        /** @var array<string, mixed> $overrun */
        $overrun = is_array($data['overrun'] ?? null) ? $data['overrun'] : [];
        /** @var array<int, array<string, mixed>> $overrunRows */
        $overrunRows = is_array($overrun['by_doctor'] ?? null) ? $overrun['by_doctor'] : [];
        $overrunByDoctor = [];

        foreach ($overrunRows as $row) {
            $overrunByDoctor[(int) ($row['doctor_id'] ?? 0)] = $row;
        }

        return new ReportTable(
            title: __('reports.wait_times.title'),
            subtitle: $subtitle,
            columns: [
                __('reports.column.doctor'), __('reports.column.wait_samples'), __('reports.column.wait_avg'),
                __('reports.column.wait_p90'), __('reports.column.consult_samples'), __('reports.column.consult_avg'),
                __('reports.column.consult_p90'), __('reports.column.sessions'), __('reports.column.overrun_avg'),
                __('reports.column.late_start_avg'),
            ],
            rows: function () use ($rows, $overrunByDoctor): Generator {
                foreach ($rows as $row) {
                    $session = $overrunByDoctor[(int) ($row['doctor_id'] ?? 0)] ?? [];

                    yield [
                        self::text($row['doctor_name'] ?? null),
                        self::int($row['wait_samples'] ?? null),
                        self::number($row['wait_avg_minutes'] ?? null),
                        self::number($row['wait_p90_minutes'] ?? null),
                        self::int($row['consult_samples'] ?? null),
                        self::number($row['consult_avg_minutes'] ?? null),
                        self::number($row['consult_p90_minutes'] ?? null),
                        self::int($session['sessions'] ?? null),
                        self::number($session['overrun_avg_minutes'] ?? null),
                        self::number($session['late_start_avg_minutes'] ?? null),
                    ];
                }
            },
            align: array_fill_keys(range(1, 9), 'right'),
            notes: [__('reports.note.wait'), __('reports.note.consult'), __('reports.note.overrun')],
            knownRowCount: count($rows),
        );
    }

    /** @param array<string, mixed> $data */
    private function revenue(array $data, string $subtitle): ReportTable
    {
        /** @var array<string, mixed> $collection */
        $collection = is_array($data['collection'] ?? null) ? $data['collection'] : [];
        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($collection['by_day'] ?? null) ? $collection['by_day'] : [];

        return new ReportTable(
            title: __('reports.revenue.title'),
            subtitle: $subtitle,
            columns: [
                __('reports.column.date'), __('reports.column.gross'), __('reports.column.refunds'),
                __('reports.column.net'), __('reports.column.payments'),
            ],
            rows: function () use ($rows): Generator {
                foreach ($rows as $row) {
                    yield [
                        self::text($row['date'] ?? null),
                        self::money($row['gross_paisa'] ?? null),
                        self::money($row['refunds_paisa'] ?? null),
                        self::money($row['net_paisa'] ?? null),
                        self::int($row['count'] ?? null),
                    ];
                }
            },
            align: [1 => 'right', 2 => 'right', 3 => 'right', 4 => 'right'],
            notes: [__('reports.note.collection'), __('reports.note.refund_day'), __('reports.note.commission_frozen')],
            knownRowCount: count($rows),
        );
    }

    /** @param array<string, mixed> $data */
    private function patients(array $data, string $subtitle): ReportTable
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($data['by_period'] ?? null) ? $data['by_period'] : [];
        /** @var array<string, mixed> $followUp */
        $followUp = is_array($data['follow_up'] ?? null) ? $data['follow_up'] : [];

        return new ReportTable(
            title: __('reports.patients.title'),
            subtitle: $subtitle,
            columns: [__('reports.column.period'), __('reports.column.patients'), __('reports.column.new'), __('reports.column.returning')],
            rows: function () use ($rows, $followUp): Generator {
                foreach ($rows as $row) {
                    yield [
                        self::text($row['period'] ?? null),
                        self::int($row['patients'] ?? null),
                        self::int($row['new'] ?? null),
                        self::int($row['returning'] ?? null),
                    ];
                }

                yield [__('reports.follow_up.advised'), self::int($followUp['advised'] ?? null), null, null];
                yield [__('reports.follow_up.due'), self::int($followUp['due'] ?? null), null, null];
                yield [__('reports.follow_up.booked'), self::int($followUp['booked_due'] ?? null), null, null];
                yield [__('reports.follow_up.kept'), self::int($followUp['kept_due'] ?? null), null, null];
                yield [__('reports.follow_up.rate'), self::percent($followUp['compliance_rate'] ?? null), null, null];
            },
            align: [1 => 'right', 2 => 'right', 3 => 'right'],
            notes: [__('reports.note.new_patient'), __('reports.note.doctor_overlap'), __('reports.note.follow_up')],
            knownRowCount: count($rows) + 5,
        );
    }

    /** @param array<string, mixed> $data */
    private function clinical(array $data, string $subtitle): ReportTable
    {
        /** @var array<string, mixed> $diagnoses */
        $diagnoses = is_array($data['diagnoses'] ?? null) ? $data['diagnoses'] : [];
        /** @var array<string, mixed> $drugs */
        $drugs = is_array($data['drugs'] ?? null) ? $data['drugs'] : [];
        /** @var array<int, array<string, mixed>> $dxRows */
        $dxRows = is_array($diagnoses['rows'] ?? null) ? $diagnoses['rows'] : [];
        /** @var array<int, array<string, mixed>> $genericRows */
        $genericRows = is_array($drugs['by_generic'] ?? null) ? $drugs['by_generic'] : [];
        /** @var array<int, array<string, mixed>> $brandRows */
        $brandRows = is_array($drugs['by_brand'] ?? null) ? $drugs['by_brand'] : [];

        return new ReportTable(
            title: __('reports.clinical.title'),
            subtitle: $subtitle,
            columns: [
                __('reports.column.section'), __('reports.column.code'), __('reports.column.name'),
                __('reports.column.count'), __('reports.column.patients'),
            ],
            rows: function () use ($dxRows, $genericRows, $brandRows): Generator {
                foreach ($dxRows as $row) {
                    yield [
                        __('reports.clinical.diagnoses'),
                        self::text($row['icd10_code'] ?? null),
                        self::text($row['title'] ?? null),
                        self::int($row['visits'] ?? null),
                        self::int($row['patients'] ?? null),
                    ];
                }

                foreach ($genericRows as $row) {
                    yield [__('reports.clinical.generics'), null, self::text($row['name'] ?? null), self::int($row['items'] ?? null), self::int($row['patients'] ?? null)];
                }

                foreach ($brandRows as $row) {
                    yield [__('reports.clinical.brands'), null, self::text($row['name'] ?? null), self::int($row['items'] ?? null), self::int($row['patients'] ?? null)];
                }
            },
            align: [3 => 'right', 4 => 'right'],
            notes: [__('reports.note.diagnosis_unit'), __('reports.note.uncoded'), __('reports.note.issued_only'), __('reports.note.snapshot')],
            knownRowCount: count($dxRows) + count($genericRows) + count($brandRows),
        );
    }

    /** @param array<string, mixed> $data */
    private function peakHours(array $data, string $subtitle): ReportTable
    {
        /** @var array<int, array<int, int>> $grid */
        $grid = is_array($data['grid'] ?? null) ? $data['grid'] : [];
        $metric = is_string($data['metric'] ?? null) ? $data['metric'] : 'arrivals';

        return new ReportTable(
            title: __('reports.peak_hours.title'),
            subtitle: $subtitle.' — '.__('reports.metric.'.$metric),
            columns: array_merge([__('reports.column.weekday')], array_map(static fn (int $h): string => sprintf('%02d', $h), range(0, 23))),
            rows: function () use ($grid): Generator {
                foreach ($grid as $weekday => $hours) {
                    yield array_merge([__('reports.weekday.'.$weekday)], array_map(static fn (mixed $n): int => (int) $n, array_values($hours)));
                }
            },
            align: array_fill_keys(range(1, 24), 'right'),
            notes: [__('reports.note.peak_metric'), __('reports.note.local_time')],
            knownRowCount: count($grid),
        );
    }

    /** @param array<string, mixed> $data */
    private function dashboard(array $data, string $subtitle): ReportTable
    {
        /** @var array<string, mixed> $appointments */
        $appointments = is_array($data['appointments'] ?? null) ? $data['appointments'] : [];
        /** @var array<string, mixed> $waits */
        $waits = is_array($data['waits'] ?? null) ? $data['waits'] : [];
        /** @var array<string, mixed>|null $money */
        $money = is_array($data['money'] ?? null) ? $data['money'] : null;

        return new ReportTable(
            title: __('reports.dashboard.title'),
            subtitle: $subtitle,
            columns: [__('reports.column.metric'), __('reports.column.value')],
            rows: function () use ($appointments, $waits, $money): Generator {
                yield [__('reports.column.booked'), self::int($appointments['booked'] ?? null)];
                yield [__('reports.dashboard.arrived'), self::int($appointments['completed'] ?? null) + self::int($appointments['open'] ?? null)];
                yield [__('reports.column.completed'), self::int($appointments['completed'] ?? null)];
                yield [__('reports.column.no_show'), self::int($appointments['no_show'] ?? null)];
                yield [__('reports.column.wait_avg'), self::number($waits['wait_avg_minutes'] ?? null)];
                yield [__('reports.column.consult_avg'), self::number($waits['consult_avg_minutes'] ?? null)];

                if ($money !== null) {
                    yield [__('reports.column.net'), self::money($money['net_paisa'] ?? null)];
                }
            },
            align: [1 => 'right'],
            notes: [__('reports.note.today')],
            knownRowCount: $money === null ? 6 : 7,
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** Plain decimal taka: a spreadsheet must be able to sum the column, so no ৳ and no thousands separator. */
    private static function money(mixed $value): string
    {
        return Paisa::toDecimal(is_numeric($value) ? (int) $value : 0);
    }

    private static function percent(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
