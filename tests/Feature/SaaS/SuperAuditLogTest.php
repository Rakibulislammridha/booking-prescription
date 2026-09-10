<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\SuperAdmin;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** The audit screen's filters and its CSV export — and the fact that exporting the log is itself logged. */
final class SuperAuditLogTest extends TestCase
{
    public function test_the_screen_filters_by_clinic_operator_action_date_and_free_text_on_the_target(): void
    {
        $me = $this->actingAsSuper();
        $other = SuperAdmin::factory()->create(['name' => 'Other Op']);
        $a = $this->tenant('a');
        $b = $this->tenant('b');
        $today = CarbonImmutable::now();

        AuditLogCentral::factory()->create(['super_admin_id' => $me->id, 'tenant_id' => $a->id, 'action' => CentralAuditAction::Suspend, 'auditable_type' => 'App\\Models\\Central\\Tenant', 'auditable_id' => $a->id, 'before' => ['status' => 'active'], 'after' => ['status' => 'suspended', 'reason' => 'abuse investigation'], 'occurred_at' => $today]);
        AuditLogCentral::factory()->create(['super_admin_id' => $other->id, 'tenant_id' => $b->id, 'action' => CentralAuditAction::PlanChange, 'auditable_type' => 'App\\Models\\Central\\Subscription', 'auditable_id' => 4410, 'before' => ['plan' => 'starter'], 'after' => ['plan' => 'pro'], 'occurred_at' => $today->subDays(10)]);
        AuditLogCentral::factory()->create(['super_admin_id' => null, 'tenant_id' => null, 'action' => CentralAuditAction::SettingsChange, 'auditable_type' => 'App\\Models\\Central\\PlatformSetting', 'auditable_id' => 1, 'after' => ['key' => 'security.super_two_factor', 'value' => 'disabled'], 'occurred_at' => $today->subDays(40)]);

        $this->get('/audit')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Super/Audit/Index')
                ->where('meta.total', fn ($n) => $n >= 3)
                ->where('filters.action', '')
                ->has('options.admins')
                ->has('options.tenants')
                ->has('export_limit')
                ->has('logs.0.user_agent')
                ->has('logs.0.request_id'));

        $this->get('/audit?tenant='.$a->public_id)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('meta.total', 1)->where('logs.0.action', 'suspend')->where('filters.tenant', $a->public_id));

        $this->get('/audit?admin='.$other->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('meta.total', 1)->where('logs.0.actor', 'Other Op')->where('filters.admin', (string) $other->id));

        $this->get('/audit?action=settings_change')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('meta.total', 1)->where('logs.0.actor', null));

        $from = $today->subDays(15)->setTimezone('Asia/Dhaka')->toDateString();
        $to = $today->subDays(5)->setTimezone('Asia/Dhaka')->toDateString();
        $this->get('/audit?from='.$from.'&to='.$to)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('meta.total', 1)->where('logs.0.action', 'plan_change'));

        // Free text on the target: the auditable id, the class, and a value inside the change.
        $this->get('/audit?q=4410')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('meta.total', 1)->where('logs.0.auditable_id', 4410));
        $this->get('/audit?q=PlatformSetting')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('meta.total', 1)->where('logs.0.action', 'settings_change'));
        $this->get('/audit?q=abuse%20investigation')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('meta.total', 1)->where('logs.0.action', 'suspend'));
        $this->get('/audit?q=nothing-like-this')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('meta.total', 0));

        // A bad filter is a validation error, not a 500.
        $this->from('/audit')->get('/audit?action=nope')->assertRedirect('http://super.bp.test/audit')->assertSessionHasErrors('action');
        $this->from('/audit')->get('/audit?from=2026-09-10&to=2026-09-01')->assertSessionHasErrors('to');
    }

    public function test_the_export_streams_the_filtered_rows_as_csv_and_is_audited(): void
    {
        $me = $this->actingAsSuper();
        $a = $this->tenant('a');
        AuditLogCentral::factory()->create(['super_admin_id' => $me->id, 'tenant_id' => $a->id, 'action' => CentralAuditAction::Suspend, 'after' => ['reason' => 'ব্যাংক বকেয়া'], 'ip' => '203.0.113.7', 'user_agent' => 'phpunit/1']);
        AuditLogCentral::factory()->create(['super_admin_id' => $me->id, 'tenant_id' => null, 'action' => CentralAuditAction::View]);

        $response = $this->get('/audit/export?action=suspend');
        $response->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment; filename=audit-log-', (string) $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'BOM so Excel reads Bangla');
        $this->assertStringContainsString('id,occurred_at,action,actor,actor_id,tenant,tenant_slug,auditable_type,auditable_id,before,after,ip,user_agent,request_id', $lines[0]);
        $this->assertCount(2, $lines, 'header + the one suspend row; the view row is filtered out');
        $this->assertStringContainsString('suspend', $lines[1]);
        $this->assertStringContainsString($a->slug, $lines[1]);
        $this->assertStringContainsString('ব্যাংক বকেয়া', $lines[1]);
        $this->assertStringContainsString('203.0.113.7', $lines[1]);

        $log = AuditLogCentral::query()->where('action', CentralAuditAction::Export->value)->where('super_admin_id', $me->id)->latest('id')->firstOrFail();
        $this->assertSame('audit_log', $log->after['what'] ?? null);
        $this->assertSame(1, $log->after['rows'] ?? null);
        $this->assertSame(['action' => 'suspend'], $log->after['filters'] ?? null);
    }

    public function test_the_audit_screen_and_export_are_closed_to_guests(): void
    {
        $this->asCentral();
        $this->get('/audit')->assertRedirect('http://super.bp.test/login');
        $this->get('/audit/export')->assertRedirect('http://super.bp.test/login');
    }
}
