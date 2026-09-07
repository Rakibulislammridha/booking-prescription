<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\Notification;
use App\Models\Tenant\User;

/**
 * The outbound log carries rendered message bodies — a patient's name, serial and clinic in plain text — so
 * reading it is its own permission (`notifications.logs.view`, hospital admin + accountant: SMS credits are money
 * and the log is how they are reconciled).
 *
 * Causing a message to be sent again is not a read: `retry` spends a credit and re-delivers a clinical message to
 * a patient, so it is `notifications.send.test` — the same permission as the gateway test send, hospital admin
 * only. Push subscriptions are channel plumbing rather than a log, so `managePush` follows the gateways.
 */
final class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->can(Permission::NotificationsLogsView->value);
    }

    public function view(User $user, Notification $notification): bool
    {
        return $this->viewAny($user);
    }

    public function retry(User $user, Notification $notification): bool
    {
        return $user->is_active && $user->can(Permission::NotificationsSendTest->value);
    }

    /** The staff push-subscription screen (list + revoke a device), not part of the outbound log. */
    public function managePush(User $user): bool
    {
        return $user->is_active && $user->can(Permission::NotificationsGatewaysManage->value);
    }
}
