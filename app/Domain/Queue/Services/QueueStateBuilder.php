<?php

declare(strict_types=1);

namespace App\Domain\Queue\Services;

use App\Domain\Serials\Enums\SerialPriority;
use App\Domain\Serials\Enums\SerialStatus;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the QueueState document (REALTIME.md §4.1): one query for the session (+ doctor/branch), one for the active
 * serials ordered by position, one for last_called. Short keys, codes and statuses only — never patient identifiers.
 */
final class QueueStateBuilder
{
    public const VERSION = 1;

    public const TRUNCATE_ABOVE = 120;

    public const WINDOW = 80;

    public function __construct(private readonly EtaCalculator $eta) {}

    /** @return array<string, mixed> */
    public function build(SessionInstance $session, int $version, CarbonImmutable $now): array
    {
        $session->loadMissing(['doctor', 'branch']);

        /** @var Collection<int, Serial> $active */
        $active = Serial::query()
            ->where('session_instance_id', $session->id)
            ->whereIn('status', SerialStatus::activeValues())
            ->orderBy('position')->orderBy('number')
            ->get(['id', 'public_id', 'session_instance_id', 'number', 'display_code', 'position', 'status', 'priority', 'called_at', 'slot_start_at', 'estimated_call_at']);

        $etas = $this->eta->estimate($session, $active, $now);

        $nowServing = $session->now_serving_serial_id === null ? null : $active->first(fn (Serial $s) => $s->id === $session->now_serving_serial_id);

        /** @var Collection<int, Serial> $lastCalled */
        $lastCalled = Serial::query()
            ->where('session_instance_id', $session->id)
            ->whereNotNull('called_at')
            ->orderByDesc('called_at')->limit(3)
            ->get(['id', 'display_code', 'called_at']);

        $serials = [];
        $ahead = 0;

        foreach ($active as $serial) {
            $row = [
                'id' => $serial->public_id,
                'c' => $serial->display_code,
                'n' => $serial->number,
                'p' => $serial->position,
                's' => self::short($serial->status),
            ];

            $priority = self::priority($serial->priority);

            if ($priority !== null) {
                $row['pr'] = $priority;
            }

            $eta = $etas[$serial->id] ?? null;
            // Presentation rule (SERIAL_ENGINE §13): rounded up to 5 minutes, never earlier than now + 1 min — applied
            // server-side so the patient page, the display board and serials.estimated_call_at all show one number.
            $row['eta'] = $eta === null ? null : EtaCalculator::displayed($eta, $now)->toIso8601ZuluString();
            $row['ahead'] = $serial->status === SerialStatus::InConsultation ? 0 : $ahead;
            $serials[] = $row;

            if ($serial->status !== SerialStatus::InConsultation) {
                $ahead++;
            }
        }

        $truncated = false;

        if (count($serials) > self::TRUNCATE_ABOVE) {
            $index = 0;

            foreach ($serials as $i => $row) {
                if ($nowServing !== null && $row['id'] === $nowServing->public_id) {
                    $index = $i;
                    break;
                }
            }

            $start = max(0, $index - self::WINDOW);
            $serials = array_slice($serials, $start, self::WINDOW * 2 + 1);
            $truncated = true;
        }

        $counts = [
            'booked' => $session->booked_count,
            'checked_in' => $session->checked_in_count,
            'in_consultation' => $session->in_consultation_count,
            'completed' => $session->completed_count,
            'no_show' => $session->no_show_count,
            'cancelled' => $session->cancelled_count,
            'postponed' => $session->postponed_count,
        ];
        $counts['waiting'] = $counts['booked'] + $counts['checked_in'];

        $state = [
            'v' => self::VERSION,
            'session' => [
                'id' => $session->public_id,
                'code' => $session->session_code,
                'date' => $session->session_date->toDateString(),
                'status' => $session->status->value,
                'mode' => $session->mode->value,
                'planned_start_at' => $session->planned_start_at->toIso8601ZuluString(),
                'expected_start_at' => $session->expectedStartAt()->toIso8601ZuluString(),
                'delay_minutes' => $session->delay_minutes,
                'doctor' => [
                    'id' => $session->doctor->public_id,
                    'slug' => $session->doctor->slug,
                    'name' => $session->doctor->name,
                    'name_bn' => $session->doctor->name_bn,
                    'room' => $session->doctor->room_label,
                ],
                'branch' => ['id' => $session->branch->public_id, 'name' => $session->branch->name],
            ],
            'now_serving' => $nowServing === null ? null : [
                'id' => $nowServing->public_id,
                'c' => $nowServing->display_code,
                'n' => $nowServing->number,
                'called_at' => $nowServing->called_at?->toIso8601ZuluString() ?? $now->toIso8601ZuluString(),
            ],
            'last_called' => $lastCalled->map(fn (Serial $s) => ['c' => $s->display_code, 'called_at' => $s->called_at?->toIso8601ZuluString()])->values()->all(),
            'counts' => $counts,
            'avg_consult_seconds' => $session->avg_consult_seconds,
            'eta_confidence' => $this->eta->confidence($session),
            'serials' => $serials,
        ];

        if ($truncated) {
            $state['truncated'] = true;
        }

        $state['updated_at'] = $now->toIso8601ZuluString();
        $state['version'] = $version;

        return $state;
    }

    /**
     * ETA cache for slips/SMS (`serials.estimated_call_at`, REALTIME.md §14.1) — the ids whose cached value differs
     * by a minute or more from the fresh estimate, keyed by serial id.
     *
     * @param  Collection<int, Serial>  $active
     * @param  array<int, CarbonImmutable|null>  $etas
     * @return array<int, CarbonImmutable|null>
     */
    public static function staleEtaCache(Collection $active, array $etas): array
    {
        $out = [];

        foreach ($active as $serial) {
            $fresh = $etas[$serial->id] ?? null;
            $cached = $serial->estimated_call_at;

            if ($fresh === null && $cached === null) {
                continue;
            }

            if ($fresh !== null && $cached !== null && abs($fresh->getTimestamp() - $cached->getTimestamp()) < 60) {
                continue;
            }

            $out[$serial->id] = $fresh;
        }

        return $out;
    }

    public static function short(SerialStatus $status): string
    {
        return match ($status) {
            SerialStatus::Booked => 'b',
            SerialStatus::CheckedIn => 'c',
            SerialStatus::InConsultation => 'i',
            default => 'b',
        };
    }

    public static function priority(SerialPriority $priority): ?string
    {
        return match ($priority) {
            SerialPriority::Emergency => 'e',
            SerialPriority::Vip => 'v',
            SerialPriority::Elderly => 'el',
            SerialPriority::Normal => null,
        };
    }
}
