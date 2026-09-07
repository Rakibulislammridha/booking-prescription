<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\PrescriptionTemplate;
use App\Models\Tenant\User;

/** Own templates + clinic-shared + other doctors' `is_shared`; edit/delete = owner or `prescriptions.view.any` (admin). */
final class PrescriptionTemplatePolicy
{
    public function view(User $user, PrescriptionTemplate $template): bool
    {
        $doctorId = $user->doctor()->value('id');

        return $user->is_active && ($template->doctor_id === null || $template->is_shared || ($doctorId !== null && (int) $doctorId === $template->doctor_id) || $user->can(Permission::PrescriptionsViewAny->value));
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->can(Permission::PrescriptionsWrite->value);
    }

    public function update(User $user, PrescriptionTemplate $template): bool
    {
        $doctorId = $user->doctor()->value('id');

        return $user->is_active && $user->can(Permission::PrescriptionsWrite->value) && (($doctorId !== null && (int) $doctorId === $template->doctor_id) || $user->can(Permission::PrescriptionsViewAny->value));
    }

    public function delete(User $user, PrescriptionTemplate $template): bool
    {
        return $this->update($user, $template);
    }
}
