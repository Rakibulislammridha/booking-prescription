<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Prescription\Actions\RequestDelivery;
use App\Domain\Prescription\Actions\SaveDrawing;
use App\Domain\Prescription\Actions\SaveHandwritingPage;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Prescription\DrawingRequest;
use App\Http\Requests\Panel\Prescription\HandwritingRequest;
use App\Http\Requests\Panel\Prescription\SendRequest;
use App\Models\Tenant\Prescription;
use Illuminate\Http\JsonResponse;

/** POST …/handwriting (§4.12) · POST …/drawing (§4.11) · POST …/send (§7.7). */
final class AttachmentController extends Controller
{
    public function handwriting(HandwritingRequest $request, Prescription $prescription, SaveHandwritingPage $save): JsonResponse
    {
        $path = $save->handle($prescription, (int) $request->validated('page'), $request->file('png'), Actor::fromRequest($request));

        return response()->json(['page' => (int) $request->validated('page'), 'path' => $path, 'handwriting_image_path' => $prescription->fresh()->handwriting_image_path, 'mode' => 'handwriting'], 201);
    }

    public function drawing(DrawingRequest $request, Prescription $prescription, SaveDrawing $save): JsonResponse
    {
        $rx = $save->handle($prescription, (array) $request->validated('json'), $request->file('png'), Actor::fromRequest($request));

        return response()->json(['drawing_json' => $rx->drawing_json, 'drawing_image_path' => $rx->drawing_image_path]);
    }

    public function send(SendRequest $request, Prescription $prescription, RequestDelivery $deliver): JsonResponse
    {
        $event = $deliver->handle($prescription, (string) $request->validated('channel'), $request->validated('to'), Actor::fromRequest($request));

        return response()->json(['queued' => true, 'channel' => $event->channel, 'pdf_status' => $event->pdfPath === null ? 'pending' : 'ready'], 202);
    }
}
