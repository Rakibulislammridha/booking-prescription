<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Actions\QueueNotification;
use App\Domain\Notifications\Actions\SendNotification;
use App\Domain\Notifications\Data\DeliveryResult;
use App\Domain\Notifications\Data\NotificationRequest;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Enums\NotificationLogStatus;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Events\NotificationDeadLettered;
use App\Domain\Notifications\Events\SmsSent;
use App\Domain\Notifications\Exceptions\GatewayRateLimited;
use App\Domain\Notifications\Jobs\SendNotificationJob;
use App\Models\Tenant\Notification;
use App\Models\Tenant\NotificationLog;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Notifications\Concerns\NotificationFixtures;
use Tests\Feature\Notifications\Concerns\ScriptedDriver;
use Tests\TestCase;

/**
 * The sending pipeline: per-attempt logging, the bounded retry ladder, permanent-versus-transient classification,
 * the dead-letter state and the per-tenant rate limit.
 */
final class SendPipelineTest extends TestCase
{
    use NotificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    private function queued(string $body = 'Serial A-012'): Notification
    {
        return Notification::factory()->create(['body' => $body, 'status' => NotificationStatus::Queued, 'segments' => 1]);
    }

    public function test_a_successful_send_marks_sent_logs_the_attempt_and_meters_the_segments(): void
    {
        Event::fake([SmsSent::class, NotificationDeadLettered::class]);
        $this->bindDriver(new ScriptedDriver([DeliveryResult::sent('gw-1', ['ok' => true], 42)]));
        $notification = $this->queued();

        app(SendNotification::class)->handle($notification);

        $notification->refresh();
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertNotNull($notification->sent_at);
        $this->assertSame(1, $notification->attempts);
        $this->assertNull($notification->last_error);

        $log = NotificationLog::query()->where('notification_id', $notification->id)->firstOrFail();
        $this->assertSame(1, $log->attempt_no);
        $this->assertSame('scripted', $log->provider);
        $this->assertSame('gw-1', $log->provider_message_id);
        $this->assertSame(NotificationLogStatus::Sent, $log->status);
        $this->assertSame(42, $log->latency_ms);

        Event::assertDispatched(SmsSent::class, fn (SmsSent $e) => $e->notificationId === $notification->id && $e->segments === 1);
        Event::assertNotDispatched(NotificationDeadLettered::class);
    }

    public function test_a_permanent_rejection_dead_letters_on_the_first_attempt(): void
    {
        Event::fake([NotificationDeadLettered::class, SmsSent::class]);
        $this->bindDriver(new ScriptedDriver([DeliveryResult::rejected('INVALID_NUMBER', ['error' => 'INVALID_NUMBER'])]));
        $notification = $this->queued();

        app(SendNotification::class)->handle($notification);

        $notification->refresh();
        $this->assertSame(NotificationStatus::Failed, $notification->status, 'a refused number must not be retried three more times');
        $this->assertSame(1, $notification->attempts);
        $this->assertSame('INVALID_NUMBER', $notification->last_error);
        $this->assertTrue($notification->isDeadLettered());

        Event::assertDispatched(NotificationDeadLettered::class, fn (NotificationDeadLettered $e) => $e->permanent === true);
        Event::assertNotDispatched(SmsSent::class);
    }

    public function test_a_transient_failure_stays_queued_until_the_ladder_is_exhausted(): void
    {
        config(['notifications.retry.tries' => 3]);
        $this->bindDriver(new ScriptedDriver([DeliveryResult::failed('SYSTEM_BUSY')]));
        $notification = $this->queued();
        $send = app(SendNotification::class);

        $send->handle($notification);
        $this->assertSame(NotificationStatus::Queued, $notification->refresh()->status);
        $this->assertSame(1, $notification->attempts);

        $send->handle($notification);
        $this->assertSame(NotificationStatus::Queued, $notification->refresh()->status);
        $this->assertSame(2, $notification->attempts);

        $send->handle($notification);
        $notification->refresh();
        $this->assertSame(NotificationStatus::Failed, $notification->status);
        $this->assertSame(3, $notification->attempts);
        $this->assertSame(3, NotificationLog::query()->where('notification_id', $notification->id)->count(), 'every attempt leaves a log row');
        $this->assertSame([1, 2, 3], NotificationLog::query()->where('notification_id', $notification->id)->orderBy('attempt_no')->pluck('attempt_no')->all());
    }

    public function test_a_transient_failure_followed_by_a_success_ends_sent_with_both_attempts_logged(): void
    {
        $this->bindDriver(new ScriptedDriver([DeliveryResult::failed('SYSTEM_BUSY'), DeliveryResult::sent('gw-2')]));
        $notification = $this->queued();
        $send = app(SendNotification::class);

        $send->handle($notification);
        $send->handle($notification->refresh());

        $notification->refresh();
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertSame(2, $notification->attempts);
        $this->assertNull($notification->last_error);
        $this->assertSame([NotificationLogStatus::Failed, NotificationLogStatus::Sent], NotificationLog::query()->where('notification_id', $notification->id)->orderBy('attempt_no')->pluck('status')->all());
    }

    public function test_a_terminal_notification_is_never_sent_again(): void
    {
        $driver = new ScriptedDriver([DeliveryResult::sent('gw')]);
        $this->bindDriver($driver);
        $notification = Notification::factory()->failed()->create();

        app(SendNotification::class)->handle($notification);

        $this->assertSame([], $driver->received);
    }

    public function test_the_rate_limiter_throws_before_an_attempt_is_burned(): void
    {
        config(['notifications.rate_limit.per_minute' => 1]);
        $this->bindDriver(new ScriptedDriver([DeliveryResult::sent('gw')]));
        $send = app(SendNotification::class);

        $send->handle($this->queued());
        $second = $this->queued();

        try {
            $send->handle($second);
            $this->fail('expected GatewayRateLimited');
        } catch (GatewayRateLimited $e) {
            $this->assertSame('notifications.rate_limited', $e->code());
            $this->assertSame(429, $e->status());
        }

        $second->refresh();
        $this->assertSame(0, $second->attempts, 'a throttled message must not lose a retry');
        $this->assertSame(NotificationStatus::Queued, $second->status);
    }

    public function test_the_job_is_idempotent_for_an_already_sent_row(): void
    {
        $driver = new ScriptedDriver([DeliveryResult::sent('gw')]);
        $this->bindDriver($driver);
        $notification = Notification::factory()->sent()->create();

        app(SendNotificationJob::class, ['notificationId' => $notification->id])->handle(app(SendNotification::class));

        $this->assertSame([], $driver->received, 'a duplicate dispatch must not send the message twice');
    }

    public function test_the_job_runs_on_the_notifications_queue_and_carries_its_tenant(): void
    {
        Bus::fake();
        $this->recordingDriver();

        app(QueueNotification::class)->handle(new NotificationRequest(
            event: NotificationEvent::BookingConfirmed,
            channel: NotificationChannel::Sms,
            patient: $this->patient(),
            variables: ['clinic' => 'X'],
        ));

        Bus::assertDispatched(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->queue === 'notifications' && $job->tenantId === 9001);
    }

    public function test_the_backoff_ladder_comes_from_config(): void
    {
        config(['notifications.retry.backoff' => [30, 90, 600], 'notifications.retry.tries' => 4]);
        $job = new SendNotificationJob(1);

        $this->assertSame([30, 90, 600], $job->backoff());
        $this->assertSame(4, $job->tries());
    }

    public function test_a_scheduled_row_is_not_dispatched_until_it_is_due(): void
    {
        Bus::fake();
        $this->recordingDriver();

        $rows = app(QueueNotification::class)->handle(new NotificationRequest(
            event: NotificationEvent::FollowupDue,
            channel: NotificationChannel::Sms,
            patient: $this->patient(),
            variables: ['clinic' => 'X'],
            scheduledFor: now()->addWeek()->toImmutable(),
        ));

        $this->assertSame(NotificationStatus::Scheduled, $rows[0]->status);
        Bus::assertNotDispatched(SendNotificationJob::class);
    }
}
