<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\CentralAuditAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `audit_logs_central.action` gains the super-admin account-management actions (SCHEMA §2.13): `deactivate`,
 * `two_factor_reset`, `password_change` and `session_revoke`. Same mechanism as the previous extension — the
 * CHECK is rebuilt from `CentralAuditAction::values()`, so the enum stays the single source.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'audit_logs_central_action_check';

    /** The list as it stood before this migration, for the down path. */
    private const PREVIOUS = [
        'login', 'logout', 'impersonate', 'impersonate_end', 'create', 'update', 'delete', 'suspend',
        'reactivate', 'plan_change', 'export', 'restore', 'catalog_promote', 'settings_change', 'view',
        'two_factor_enabled', 'two_factor_disabled', 'two_factor_failed', 'two_factor_recovery_used',
    ];

    public function up(): void
    {
        $this->replaceCheck(CentralAuditAction::values());
    }

    public function down(): void
    {
        DB::table('public.audit_logs_central')->whereNotIn('action', self::PREVIOUS)->delete();

        $this->replaceCheck(self::PREVIOUS);
    }

    /** @param  array<int, string>  $actions */
    private function replaceCheck(array $actions): void
    {
        $list = implode(', ', array_map(fn (string $a) => "'".$a."'", $actions));

        DB::statement('ALTER TABLE public.audit_logs_central DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement('ALTER TABLE public.audit_logs_central ADD CONSTRAINT '.self::CONSTRAINT.' CHECK (action IN ('.$list.'))');
    }
};
