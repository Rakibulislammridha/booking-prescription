<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reception;

use App\Domain\Reception\Services\BootstrapBuilder;
use App\Http\Controllers\Api\Reception\Concerns\ResolvesDevice;
use App\Http\Controllers\Controller;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/reception/bootstrap?date=today — everything the PWA caches (OFFLINE §5.1). */
final class BootstrapController extends Controller
{
    use ResolvesDevice;

    public function __invoke(Request $request, BootstrapBuilder $builder): JsonResponse
    {
        $date = (string) $request->query('date', '');
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::parse($date, Clock::timezone())->startOfDay() : Clock::today();

        return response()->json($builder->build($this->device($request), $this->actorUser($request), $day))->header('Cache-Control', 'no-store');
    }
}
