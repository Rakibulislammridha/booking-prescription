<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\AdviceSnippet;
use App\Models\Tenant\User;

final class AdviceSnippetPolicy
{
    public function view(User $user, AdviceSnippet $snippet): bool
    {
        $doctorId = $user->doctor()->value('id');

        return $user->is_active && ($snippet->doctor_id === null || $snippet->is_shared || ($doctorId !== null && (int) $doctorId === $snippet->doctor_id) || $user->can(Permission::PrescriptionsViewAny->value));
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->can(Permission::PrescriptionsWrite->value);
    }

    public function update(User $user, AdviceSnippet $snippet): bool
    {
        $doctorId = $user->doctor()->value('id');

        return $user->is_active && $user->can(Permission::PrescriptionsWrite->value) && (($doctorId !== null && (int) $doctorId === $snippet->doctor_id) || $user->can(Permission::PrescriptionsViewAny->value));
    }

    public function delete(User $user, AdviceSnippet $snippet): bool
    {
        return $this->update($user, $snippet);
    }
}
