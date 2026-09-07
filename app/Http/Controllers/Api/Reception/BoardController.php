<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reception;

use App\Domain\Reception\Services\BoardBuilder;
use App\Http\Controllers\Api\Reception\Concerns\ResolvesDevice;
use App\Http\Controllers\Controller;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/reception/board?date= — the board JSON the PWA polls every 5 s in degraded mode. */
final class BoardController extends Controller
{
    use ResolvesDevice;

    public function __invoke(Request $request, BoardBuilder $board): JsonResponse
    {
        $device = $this->device($request)->loadMissing('branch');
        $date = (string) $request->query('date', '');
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::parse($date, Clock::timezone())->startOfDay() : Clock::today();

        return response()->json($board->build($device->branch, $day))->header('Cache-Control', 'no-store');
    }
}
