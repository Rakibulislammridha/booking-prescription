<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Serials;

use App\Domain\Serials\Actions\AllocateSerial;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Serials\StoreSerialRequest;
use App\Http\Resources\Serials\SerialResource;
use App\Models\Tenant\SessionInstance;
use Illuminate\Http\JsonResponse;

/** POST /sessions/{session}/serials — counter / walk-in issuing from the desk (SERIAL_ENGINE §11.1, §16). */
final class SerialController extends Controller
{
    public function store(StoreSerialRequest $request, SessionInstance $session, AllocateSerial $allocate): JsonResponse
    {
        $serial = $allocate($request->toData());

        return (new SerialResource($serial->load('sessionInstance')))->response()->setStatusCode(201);
    }
}
