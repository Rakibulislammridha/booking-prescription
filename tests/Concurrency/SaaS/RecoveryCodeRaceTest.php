<?php

declare(strict_types=1);

namespace Tests\Concurrency\SaaS;

use App\Domain\SaaS\Services\SuperTwoFactor;
use App\Domain\SaaS\Services\Totp;
use App\Models\Central\SuperAdmin;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ProcessPool;
use Tests\TestCase;

/**
 * B5 regression (was tests/Feature/Audit2/RecoveryCodeRaceTest). A recovery code must be single-use even when it
 * is presented by several processes at the same instant. `verifyRecoveryCode` was a lock-free read-strike-save, so
 * six parallel presentations of one code all won. It now consumes the digest inside `FOR UPDATE` on the
 * super_admins row, so whatever the interleaving, EXACTLY ONE presentation is accepted.
 *
 * Committed state + real parallel processes (CONVENTIONS §6.5): the transaction wrapper cannot prove a row lock.
 */
#[Group('concurrency')]
final class RecoveryCodeRaceTest extends TestCase
{
    /** No transaction: the admin row must be committed, or the worker processes could not see it. */
    protected array $connectionsToTransact = [];

    private ?int $adminId = null;

    protected function tearDown(): void
    {
        if ($this->adminId !== null) {
            DB::connection('pgsql')->table('public.audit_logs_central')->where('super_admin_id', $this->adminId)->delete();
            DB::connection('pgsql')->table('public.super_admins')->where('id', $this->adminId)->delete();
        }

        parent::tearDown();
    }

    public function test_one_recovery_code_presented_in_parallel_is_accepted_exactly_once(): void
    {
        $this->asCentral();
        $admin = SuperAdmin::factory()->create();
        $this->adminId = $admin->id;

        $twoFactor = app(SuperTwoFactor::class);
        $secret = $twoFactor->beginEnrolment($admin);
        $codes = $twoFactor->confirm($admin->refresh(), Totp::code($secret));
        $this->assertIsArray($codes);
        $this->assertSame(8, $twoFactor->recoveryCodesRemaining($admin->refresh()));

        $results = ProcessPool::run(workers: 6, command: [
            'php', 'artisan', 'saas:recovery-hammer',
            '--admin='.$admin->id, '--code='.$codes[0], '--start-at='.(microtime(true) + 2.0),
        ], timeoutSeconds: 120);

        $this->assertSame(0, $results->failed(), 'a worker process crashed');

        $accepted = 0;

        foreach ($results->lines() as $line) {
            $decoded = json_decode($line, true);
            $accepted += is_array($decoded) && ($decoded['ok'] ?? false) === true ? 1 : 0;
        }

        $this->assertSame(1, $accepted, 'a single-use recovery code was accepted more than once under concurrency');
        $this->assertSame(7, $twoFactor->recoveryCodesRemaining($admin->refresh()), 'exactly one code must have been consumed');
    }
}
