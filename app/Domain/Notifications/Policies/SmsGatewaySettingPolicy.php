<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\SmsGatewaySetting;
use App\Models\Tenant\User;

/**
 * Gateway credentials spend the clinic's money and, if leaked, let anyone send SMS as the clinic. They have their
 * own permission (`notifications.gateways.manage`, hospital admin only in RoleMatrix) rather than borrowing the
 * template permission plus a role check: a clinic that wants one trusted operator on gateways grants exactly that
 * permission, and nobody reaches credentials through a template screen.
 *
 * The test send is separate again (`notifications.send.test`): it costs a credit and puts a real message on a real
 * handset, so it is not implied by being allowed to read or edit the row.
 */
final class SmsGatewaySettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->can(Permission::NotificationsGatewaysManage->value);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, SmsGatewaySetting $gateway): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, SmsGatewaySetting $gateway): bool
    {
        return $this->viewAny($user);
    }

    public function test(User $user, SmsGatewaySetting $gateway): bool
    {
        return $user->is_active && $user->can(Permission::NotificationsSendTest->value);
    }
}
