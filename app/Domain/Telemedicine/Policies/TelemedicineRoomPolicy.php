<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\TelemedicineRoom;
use App\Models\Tenant\User;

/**
 * No new permission strings: the enum is foundation-owned and telemedicine needs none of its own. Consulting over
 * video is consulting, so it reuses `prescriptions.write` plus the same "is this your patient?" rule the
 * prescription writer applies — and the room list reuses `prescriptions.view.any` for the staff who oversee it.
 *
 * `consult` is deliberately NARROWER than the writer's `write`: `prescriptions.view.any` lets a hospital admin
 * READ a colleague's consultation, but nobody joins a video call as a doctor except that doctor. A receptionist
 * with `queue.call-next` can see the board and nothing else.
 */
final class TelemedicineRoomPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->can(Permission::PrescriptionsWrite->value) || $user->can(Permission::PrescriptionsViewAny->value) || $user->can(Permission::QueueCallNext->value));
    }

    /**
     * The room's own doctor, anyone holding `prescriptions.view.any`, or a desk OPERATOR — someone with
     * `queue.call-next` who is not themselves a doctor. That last clause is `ChannelGuards::doctor`'s rule
     * verbatim: the permission runs the queue, it does not open a colleague's chamber (BRIEF §5.N).
     */
    public function view(User $user, TelemedicineRoom $room): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($this->isTheDoctor($user, $room) || $user->can(Permission::PrescriptionsViewAny->value)) {
            return true;
        }

        return $user->can(Permission::QueueCallNext->value) && Doctor::query()->where('user_id', $user->id)->doesntExist();
    }

    public function consult(User $user, TelemedicineRoom $room): bool
    {
        return $user->is_active && $user->can(Permission::PrescriptionsWrite->value) && $this->isTheDoctor($user, $room);
    }

    public function record(User $user, TelemedicineRoom $room): bool
    {
        return $this->consult($user, $room);
    }

    private function isTheDoctor(User $user, TelemedicineRoom $room): bool
    {
        $doctorId = $user->doctor()->value('id');

        return $doctorId !== null && (int) $doctorId === $room->appointment->doctor_id;
    }
}
