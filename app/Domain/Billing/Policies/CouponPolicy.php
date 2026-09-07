<?php

declare(strict_types=1);

namespace App\Domain\Billing\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Coupon;
use App\Models\Tenant\User;

/** Coupons are a pricing decision: creating them needs discount-approval rights, using them does not. */
final class CouponPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->can(Permission::BillingInvoicesView->value);
    }

    public function view(User $user, Coupon $coupon): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->is_active && ($user->can(Permission::BillingDiscountsApprove->value) || $user->hasRole(Role::HospitalAdmin->value));
    }

    public function update(User $user, Coupon $coupon): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Coupon $coupon): bool
    {
        return $this->create($user);
    }

    public function redeem(User $user, Coupon $coupon): bool
    {
        return $user->is_active && $user->can(Permission::BillingPaymentsCollect->value);
    }
}
