<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Enums\AuditActorType;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Tenancy\Exceptions\TenancyNotInitialized;
use App\Tenancy\Facades\Tenancy;
use LogicException;
use Tests\TestCase;

final class AuditTrailTest extends TestCase
{
    public function test_create_update_and_delete_on_an_audited_model_write_before_after_rows(): void
    {
        $this->asTenant('a');
        $user = User::factory()->create(['name' => 'Before']);

        $created = $this->assertAudited(AuditAction::Create, $user);
        $this->assertNull($created->before);
        $this->assertSame('Before', $created->after['name'] ?? null);
        $this->assertArrayNotHasKey('password', $created->after ?? []);
        $this->assertSame(9001, $created->tenant_id);
        $this->assertSame(AuditActorType::System, $created->actor_type);

        $user->update(['name' => 'After']);
        $updated = $this->assertAudited(AuditAction::Update, $user);
        $this->assertSame(['name' => 'Before'], $updated->before);
        $this->assertSame(['name' => 'After'], $updated->after);

        $user->delete();
        $this->assertAudited(AuditAction::Delete, $user);
    }

    public function test_unaudited_models_write_nothing(): void
    {
        $this->asTenant('a');
        $branch = Branch::factory()->create();

        $this->assertNotAudited(AuditAction::Create, $branch);
    }

    public function test_view_is_explicit_and_carries_context_and_request_data(): void
    {
        $this->asTenant('a');
        $actor = $this->actingAsStaff(Role::Doctor);
        app(AuditRecorder::class)->setRequestId('01J0000000000000000000ABCD');
        $subject = User::factory()->create();

        $log = AuditLog::view($subject, ['reason' => 'chart review']);

        $this->assertSame(AuditAction::View, $log->action);
        $this->assertSame(AuditActorType::User, $log->actor_type);
        $this->assertSame($actor->id, $log->actor_id);
        $this->assertSame('chart review', $log->context['reason']);
        $this->assertSame('01J0000000000000000000ABCD', $log->request_id);
        $this->assertNotNull($log->ip);
        $this->assertAudited(AuditAction::View, $subject, ['reason' => 'chart review']);
    }

    public function test_impersonation_is_recorded_on_every_row(): void
    {
        $this->asTenant('a');
        $this->actingAsStaff(Role::HospitalAdmin);
        $this->withSession(['impersonated_by' => 42]);
        app('request')->setLaravelSession(app('session.store'));
        app('session.store')->put('impersonated_by', 42);

        $log = AuditLog::view(User::factory()->create());

        $this->assertSame(42, $log->impersonator_super_admin_id);
    }

    public function test_encrypted_and_secret_attributes_are_redacted(): void
    {
        $this->asTenant('a');
        $user = User::factory()->create();

        $redacted = AuditRecorder::redact($user, ['two_factor_secret' => 'abc', 'password' => 'x', 'name' => 'ok', 'remember_token' => 'y']);

        $this->assertSame(['two_factor_secret' => '[encrypted]', 'password' => '[redacted]', 'name' => 'ok', 'remember_token' => '[redacted]'], $redacted);
    }

    public function test_audit_logs_are_append_only(): void
    {
        $this->asTenant('a');
        $log = AuditLog::view(User::factory()->create());

        $this->assertThrows(fn () => $log->update(['action' => AuditAction::Delete]), LogicException::class);
        $this->assertThrows(fn () => $log->delete(), LogicException::class);
    }

    public function test_audit_requires_tenancy(): void
    {
        $this->asTenant('a');
        $user = User::factory()->create();
        Tenancy::end();

        $this->expectException(TenancyNotInitialized::class);
        AuditLog::view($user);
    }
}
