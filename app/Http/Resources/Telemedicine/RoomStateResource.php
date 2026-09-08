<?php

declare(strict_types=1);

namespace App\Http\Resources\Telemedicine;

use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Domain\Telemedicine\Services\RoomStateBuilder;
use App\Models\Tenant\TelemedicineRoom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The one wire shape for the call document (CONVENTIONS §13): the same JSON is the Inertia prop on first paint
 * and the body of the state endpoint both surfaces poll, so the client types one shape.
 *
 * @mixin TelemedicineRoom
 */
final class RoomStateResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(TelemedicineRoom $resource, private readonly ?ParticipantRole $viewer = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TelemedicineRoom $room */
        $room = $this->resource;

        return app(RoomStateBuilder::class)->build($room, $this->viewer);
    }
}
