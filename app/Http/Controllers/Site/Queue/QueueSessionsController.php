<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Queue;

use App\Domain\Queue\Services\SessionResolver;
use App\Http\Controllers\Controller;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /queue/{doctorSlug}/sessions` (`site.queue.sessions`, REALTIME.md §5.1): today's instances for the doctor so
 * the page (and the display's 10-minute self-heal, §9.3) picks up newly materialised sessions without a reload.
 */
final class QueueSessionsController extends Controller
{
    public function __invoke(Request $request, string $doctorSlug, SessionResolver $resolver): JsonResponse
    {
        $doctor = $resolver->doctor($doctorSlug);
        $date = self::date($request);
        $sessions = $resolver->today($doctor, $date);
        $current = SessionResolver::pick($sessions);

        return response()->json([
            'doctor' => ['public_id' => $doctor->public_id, 'slug' => $doctor->slug, 'name' => $doctor->name, 'name_bn' => $doctor->name_bn, 'room' => $doctor->room_label],
            'date' => $date->toDateString(),
            'current' => $current?->public_id,
            'sessions' => $sessions->map(fn (SessionInstance $s) => self::row($s))->values()->all(),
        ])->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    public static function row(SessionInstance $s): array
    {
        return [
            'public_id' => $s->public_id,
            'code' => $s->session_code,
            'date' => $s->session_date->toDateString(),
            'status' => $s->status->value,
            'mode' => $s->mode->value,
            'planned_start_at' => $s->planned_start_at->toIso8601ZuluString(),
            'expected_start_at' => $s->expectedStartAt()->toIso8601ZuluString(),
            'delay_minutes' => $s->delay_minutes,
        ];
    }

    private static function date(Request $request): CarbonImmutable
    {
        $date = (string) $request->query('date', '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            ? CarbonImmutable::parse($date, Clock::timezone())->startOfDay()
            : Clock::today();
    }
}
