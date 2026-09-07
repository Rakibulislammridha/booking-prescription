<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reception;

use App\Domain\Reception\Services\PrintTemplates;
use App\Http\Controllers\Api\Reception\Concerns\ResolvesDevice;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/reception/print-templates — 58 / 80 / A5 slip templates (OFFLINE §10), StaleWhileRevalidate in the SW. */
final class PrintTemplateController extends Controller
{
    use ResolvesDevice;

    public function __invoke(Request $request, PrintTemplates $templates): JsonResponse
    {
        $branch = $this->device($request)->loadMissing('branch')->branch;

        return response()->json(['templates' => $templates->all($branch), 'default' => $templates->defaultFormat($branch), 'version' => PrintTemplates::VERSION]);
    }
}
