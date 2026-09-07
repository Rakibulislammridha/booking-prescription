<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Reception;

use App\Domain\Clinic\Services\ActiveBranch;
use App\Domain\Reception\Services\PrintTemplates;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /panel/reception/print-templates — the same templates for a desk that has no device token yet. */
final class PrintTemplateController extends Controller
{
    public function __invoke(Request $request, ActiveBranch $activeBranch, PrintTemplates $templates): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);
        $branch = $activeBranch->current() ?? Branch::query()->active()->orderByDesc('is_main')->orderBy('id')->firstOrFail();

        return response()->json(['templates' => $templates->all($branch), 'default' => $templates->defaultFormat($branch), 'version' => PrintTemplates::VERSION]);
    }
}
