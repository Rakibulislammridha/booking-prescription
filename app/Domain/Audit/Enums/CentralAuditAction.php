<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

enum CentralAuditAction: string
{
    case Login = 'login';
    case Logout = 'logout';
    case Impersonate = 'impersonate';
    case ImpersonateEnd = 'impersonate_end';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Suspend = 'suspend';
    case Reactivate = 'reactivate';
    case PlanChange = 'plan_change';
    case Export = 'export';
    case Restore = 'restore';
    case CatalogPromote = 'catalog_promote';
    case SettingsChange = 'settings_change';
    case View = 'view';
    // Super-admin 2FA (ARCHITECTURE §6.5). Distinct actions rather than a `settings_change` with a payload: the
    // question an incident asks is "when did this account's second factor change and how many codes failed", and
    // that has to be answerable with a WHERE on `action`, not by reading JSON.
    case TwoFactorEnabled = 'two_factor_enabled';
    case TwoFactorDisabled = 'two_factor_disabled';
    case TwoFactorFailed = 'two_factor_failed';
    case TwoFactorRecoveryUsed = 'two_factor_recovery_used';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
