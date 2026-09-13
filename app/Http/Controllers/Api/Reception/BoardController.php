<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reception;

use App\Domain\Clinic\Services\DoctorScope;
use App\Domain\Reception\Services\BoardBuilder;
use App\Http\Controllers\Api\Reception\Concerns\ResolvesDevice;
use App\Http\Controllers\Controller;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/reception/board?date= — the board JSON the PWA polls every 5 s in degraded mode.
 *
 * Scoped by the ACTOR (the X-Actor-User the device names), never by the device: a shared desk tablet is one device
 * and many people, so the boundary has to follow the human. Same document, same filter as the panel board.
 */
final class BoardController extends Controller
{
    use ResolvesDevice;

    public function __invoke(Request $request, BoardBuilder $board, DoctorScope $scope): JsonResponse
    {
        $device = $this->device($request)->loadMissing('branch');
        $doctorIds = $scope->doctorIds($this->actorUser($request));
        $date = (string) $request->query('date', '');
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::parse($date, Clock::timezone())->startOfDay() : Clock::today();

        return response()->json($board->build($device->branch, $day, doctorIds: $doctorIds))->header('Cache-Control', 'no-store');
    }
}
