<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Scheduling;

use App\Domain\Scheduling\Services\AvailabilityCalendar;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/public/doctors/{slug}/availability?from&to[&branch] (api.scheduling.availability, no auth) — the public
 * calendar's per-day sessions with online remaining (SERIAL_ENGINE §16). Lives under the Panel namespace only because
 * app/Http/Controllers/Api/Scheduling is not in this module's file allowance; the route file is routes/api/scheduling.php.
 */
final class AvailabilityController extends Controller
{
    public function __invoke(Request $request, string $slug, AvailabilityCalendar $calendar): JsonResponse
    {
        $doctor = Doctor::query()->active()->where('slug', $slug)->where('accepts_online_booking', true)->firstOrFail();
        $branchSlug = (string) $request->query('branch', '');
        $branch = $branchSlug === ''
            ? Branch::query()->active()->orderByDesc('is_main')->orderBy('id')->firstOrFail()
            : Branch::query()->active()->where('slug', $branchSlug)->firstOrFail();

        $from = $this->date((string) $request->query('from', ''), Clock::today());
        $to = $this->date((string) $request->query('to', ''), $from->addDays(13));

        return response()->json([
            'doctor' => ['public_id' => $doctor->public_id, 'slug' => $doctor->slug, 'name' => $doctor->name, 'name_bn' => $doctor->name_bn],
            'branch' => ['public_id' => $branch->public_id, 'slug' => $branch->slug, 'name' => $branch->name],
            'days' => $calendar->days($doctor->id, $branch->id, max($from, Clock::today()), $to),
        ]);
    }

    private function date(string $value, CarbonImmutable $default): CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::parse($value, Clock::timezone())->startOfDay() : $default;
    }
}
