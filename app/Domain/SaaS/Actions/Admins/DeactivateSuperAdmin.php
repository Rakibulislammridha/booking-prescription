<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Admins;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Exceptions\CannotActOnOwnSuperAccount;
use App\Domain\SaaS\Exceptions\LastActiveSuperAdmin;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\SuperSessionIndex;
use App\Models\Central\SuperAdmin;
use Illuminate\Support\Facades\DB;

/**
 * The kill switch. `is_active` off, every session destroyed and the remember token rotated in the same breath —
 * an account that can no longer sign in must not stay signed in somewhere (BRIEF §5.N), and the middleware's
 * next-request check only catches a browser that makes a next request.
 *
 * Two guards, both about the platform rather than the form: not yourself (you would lock yourself out of the
 * console mid-click), and not the last active operator (nobody could ever get back in).
 */
final class DeactivateSuperAdmin
{
    public function __construct(
        private readonly CentralAudit $audit,
        private readonly SuperSessionIndex $sessions,
    ) {}

    public function handle(SuperAdmin $admin, SuperAdmin $by): SuperAdmin
    {
        if ($admin->is($by)) {
            throw new CannotActOnOwnSuperAccount;
        }

        return DB::connection('pgsql')->transaction(function () use ($admin, $by): SuperAdmin {
            $locked = SuperAdmin::query()->whereKey($admin->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->is_active) {
                return $locked;
            }

            if (SuperAdmin::query()->where('is_active', true)->whereKeyNot($locked->getKey())->doesntExist()) {
                throw new LastActiveSuperAdmin;
            }

            $locked->forceFill(['is_active' => false])->save();
            $revoked = $this->sessions->revokeAll($locked);

            $this->audit->record(CentralAuditAction::Deactivate, null, $locked, ['is_active' => true], ['is_active' => false, 'sessions_revoked' => $revoked], $by->id);

            $admin->setRawAttributes($locked->getAttributes(), sync: true);

            return $locked;
        });
    }
}
