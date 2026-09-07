<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy\Adversarial;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Enums\AuditActorType;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\Adversarial\Support\AuditViewJob;
use Tests\Feature\Tenancy\Adversarial\Support\BuildsQueuePayloads;
use Tests\TestCase;

/** Attack surface 7: what the model-event audit trail does and does not cover. */
final class AuditCoverageTest extends TestCase
{
    use BuildsQueuePayloads;

    /**
     * Documented gap: Auditable hooks created/updated/deleted model events only. Query-builder writes
     * (Builder::update/insert/upsert/delete), saveQuietly() and DB::table() leave no audit row. Any clinical write
     * must therefore go through Eloquent save()/delete() on the model (CONVENTIONS §4 actions) — bulk writes need an
     * explicit AuditRecorder::record() call.
     */
    public function test_documented_gap_query_builder_and_quiet_writes_are_not_audited(): void
    {
        $this->asTenant('a');
        $user = User::factory()->create(['name' => 'Audited']);
        $this->assertAudited(AuditAction::Create, $user);

        $user->update(['name' => 'Via model']);
        $this->assertAudited(AuditAction::Update, $user);
        $updates = fn (): int => AuditLog::query()->where('action', AuditAction::Update->value)->where('auditable_id', $user->id)->count();
        $before = $updates();

        User::query()->whereKey($user->id)->update(['name' => 'Via builder']);
        $user->forceFill(['name' => 'Quietly'])->saveQuietly();
        DB::table('users')->where('id', $user->id)->update(['name' => 'Raw']);
        $this->assertSame($before, $updates());

        $email = 'bulk@test.test';
        User::query()->insert(['public_id' => (string) Str::ulid(), 'tenant_id' => 9001, 'name' => 'Bulk', 'email' => $email, 'password' => Hash::make('x'), 'created_at' => now(), 'updated_at' => now()]);
        User::query()->upsert([['public_id' => (string) Str::ulid(), 'tenant_id' => 9001, 'name' => 'Bulk 2', 'email' => $email, 'password' => Hash::make('x'), 'created_at' => now(), 'updated_at' => now()]], ['email'], ['name']);
        $bulk = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame('Bulk 2', $bulk->name);
        $this->assertNotAudited(AuditAction::Create, $bulk);
        $this->assertNotAudited(AuditAction::Update, $bulk);

        User::query()->whereKey($bulk->id)->delete();      // soft delete through the builder
        $this->assertNotAudited(AuditAction::Delete, $bulk);
    }

    /** Guarantee: inside a queue job / console command the actor is System with no user and the row carries the tenant. */
    public function test_audit_rows_written_from_a_worker_job_and_from_console_carry_the_tenant_and_a_system_actor(): void
    {
        AuditViewJob::$auditLogId = null;
        $this->processWithWorker($this->payloadCreatedIn('a', new AuditViewJob));
        $this->assertFalse(Tenancy::check());

        $this->asTenant('a');
        $fromJob = AuditLog::query()->findOrFail(AuditViewJob::$auditLogId);
        $this->assertSame(AuditActorType::System, $fromJob->actor_type);
        $this->assertNull($fromJob->actor_id);
        $this->assertSame(9001, $fromJob->tenant_id);
        $this->assertSame(AuditAction::View, $fromJob->action);
        $this->assertSame('job', $fromJob->context['from']);

        Tenancy::end();
        $fromConsole = Tenancy::run($this->tenant('b'), fn () => AuditLog::view(Branch::query()->firstOrFail(), ['from' => 'console']));
        $this->assertSame(9002, $fromConsole->tenant_id);
        $this->assertSame(AuditActorType::System, $fromConsole->actor_type);

        $this->asTenant('a');
        $this->assertFalse(AuditLog::query()->where('context->from', 'console')->exists());   // tenant B's row is not in A
    }
}
