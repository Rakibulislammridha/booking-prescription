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
    // Super-admin account management (the console's Admins and Profile screens). `deactivate` pairs with the
    // existing `reactivate` — the auditable type says whether a row is about a clinic or an operator. A reset of
    // SOMEONE ELSE's second factor is its own action rather than a `two_factor_disabled`: the latter is the owner
    // turning their factor off with a code in hand, the former is the "locked out of the authenticator" path and
    // the question an incident asks is "who stripped whose factor, and when".
    case Deactivate = 'deactivate';
    case TwoFactorReset = 'two_factor_reset';
    case PasswordChange = 'password_change';
    case SessionRevoke = 'session_revoke';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
