<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Prescription\Actions\DeleteExternalDiagnosticCentre;
use App\Domain\Prescription\Actions\SaveExternalDiagnosticCentre;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Prescription\SaveExternalDiagnosticCentreRequest;
use App\Models\Tenant\ExternalDiagnosticCentre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /panel/external-diagnostic-centres CRUD. */
final class ExternalCentreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = ExternalDiagnosticCentre::query()->when(! $request->boolean('include_inactive'), fn ($b) => $b->active())->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn (ExternalDiagnosticCentre $c) => self::row($c))->values()->all()]);
    }

    public function store(SaveExternalDiagnosticCentreRequest $request, SaveExternalDiagnosticCentre $save): JsonResponse
    {
        return response()->json(['data' => self::row($save->handle($request->validated(), Actor::fromRequest($request)))], 201);
    }

    public function update(SaveExternalDiagnosticCentreRequest $request, ExternalDiagnosticCentre $centre, SaveExternalDiagnosticCentre $save): JsonResponse
    {
        return response()->json(['data' => self::row($save->handle($request->validated(), Actor::fromRequest($request), $centre))]);
    }

    public function destroy(Request $request, ExternalDiagnosticCentre $centre, DeleteExternalDiagnosticCentre $delete): JsonResponse
    {
        abort_unless($request->user('web')->can('prescriptions.write'), 403);
        $delete->handle($centre, Actor::fromRequest($request));

        return response()->json(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    public static function row(ExternalDiagnosticCentre $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'address' => $c->address, 'phone' => $c->phone, 'contact_person' => $c->contact_person, 'notes' => $c->notes, 'is_active' => $c->is_active];
    }
}
