<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Admins;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Exceptions\CannotActOnOwnSuperAccount;
use App\Domain\SaaS\Exceptions\LastActiveSuperAdmin;
use App\Domain\SaaS\Exceptions\SuperAdminInUse;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\SuperSessionIndex;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\ImpersonationToken;
use App\Models\Central\SuperAdmin;
use Illuminate\Support\Facades\DB;

/**
 * Remove an account that was never used — a typo'd email, a colleague who never joined. "Never used" means it
 * never signed in, never acted (no audit row names it as the actor) and never minted an impersonation token; an
 * account that did any of those is deactivated instead, so that the trail it left keeps its actor.
 *
 * The row really goes (not a soft delete): a soft-deleted operator would keep the email, and the whole point of
 * deleting a typo is to create the account again with the right one.
 */
final class DeleteSuperAdmin
{
    public function __construct(
        private readonly CentralAudit $audit,
        private readonly SuperSessionIndex $sessions,
    ) {}

    public function handle(SuperAdmin $admin, SuperAdmin $by): void
    {
        if ($admin->is($by)) {
            throw new CannotActOnOwnSuperAccount;
        }

        if (! self::neverUsed($admin)) {
            throw new SuperAdminInUse;
        }

        DB::connection('pgsql')->transaction(function () use ($admin, $by): void {
            if ($admin->is_active && SuperAdmin::query()->where('is_active', true)->whereKeyNot($admin->getKey())->doesntExist()) {
                throw new LastActiveSuperAdmin;
            }

            $snapshot = ['name' => $admin->name, 'email' => $admin->email, 'is_active' => $admin->is_active];

            $this->sessions->revokeAll($admin);
            $admin->forceDelete();

            $this->audit->record(CentralAuditAction::Delete, null, $admin, $snapshot, null, $by->id);
        });
    }

    /** The Admins screen asks this to decide whether to offer Delete or Deactivate. */
    public static function neverUsed(SuperAdmin $admin): bool
    {
        return $admin->last_login_at === null
            && AuditLogCentral::query()->where('super_admin_id', $admin->id)->doesntExist()
            && ImpersonationToken::query()->where('super_admin_id', $admin->id)->doesntExist();
    }
}
