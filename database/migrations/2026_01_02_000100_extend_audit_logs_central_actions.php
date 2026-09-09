<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\CentralAuditAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `audit_logs_central.action` gains the four super-admin 2FA actions (SCHEMA §2.13, ARCHITECTURE §6.5).
 *
 * The CHECK is rebuilt from the enum rather than typed out again, so the constraint and
 * `App\Domain\Audit\Enums\CentralAuditAction` cannot drift apart — a case added to the enum and forgotten here
 * would otherwise fail at INSERT time in production and nowhere else.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'audit_logs_central_action_check';

    /** The list as it stood before this migration, for the down path. */
    private const PREVIOUS = [
        'login', 'logout', 'impersonate', 'impersonate_end', 'create', 'update', 'delete', 'suspend',
        'reactivate', 'plan_change', 'export', 'restore', 'catalog_promote', 'settings_change', 'view',
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
