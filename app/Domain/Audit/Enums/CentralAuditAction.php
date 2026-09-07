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

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
