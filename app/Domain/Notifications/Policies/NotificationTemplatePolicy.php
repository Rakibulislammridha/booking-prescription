<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\NotificationTemplate;
use App\Models\Tenant\User;

/** Template management is `notifications.templates.manage` (ARCHITECTURE §6.2). */
final class NotificationTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->can(Permission::NotificationsTemplatesManage->value);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, NotificationTemplate $template): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, NotificationTemplate $template): bool
    {
        return $this->viewAny($user);
    }
}
