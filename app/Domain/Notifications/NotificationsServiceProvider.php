<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Booking\Events\AppointmentBooked;
use App\Domain\Notifications\Console\SendRemindersCommand;
use App\Domain\Notifications\Contracts\DriverFactory;
use App\Domain\Notifications\Contracts\PlatformGatewayDefaults;
use App\Domain\Notifications\Events\NotificationSent;
use App\Domain\Notifications\Listeners\AttachPdfOnReady;
use App\Domain\Notifications\Listeners\CancelPendingNotifications;
use App\Domain\Notifications\Listeners\DeliverPrescription;
use App\Domain\Notifications\Listeners\FanOutSessionCancellation;
use App\Domain\Notifications\Listeners\FanOutSessionDelay;
use App\Domain\Notifications\Listeners\NotifySerialMoved;
use App\Domain\Notifications\Listeners\RecordPrescriptionDelivery;
use App\Domain\Notifications\Listeners\ScheduleFollowUpReminder;
use App\Domain\Notifications\Listeners\SendBookingConfirmation;
use App\Domain\Notifications\Listeners\SendPrescriptionReady;
use App\Domain\Notifications\Listeners\SendThreeAheadSms;
use App\Domain\Notifications\Policies\NotificationPolicy;
use App\Domain\Notifications\Policies\NotificationTemplatePolicy;
use App\Domain\Notifications\Policies\SmsGatewaySettingPolicy;
use App\Domain\Notifications\Services\GatewayResolver;
use App\Domain\Notifications\Services\SegmentCounter;
use App\Domain\Notifications\Services\SmsOtpSender;
use App\Domain\Notifications\Services\VapidSigner;
use App\Domain\Notifications\Services\WebPushEncryptor;
use App\Domain\Patients\Contracts\OtpSender;
use App\Domain\Prescription\Events\FollowUpScheduled;
use App\Domain\Prescription\Events\PdfReady;
use App\Domain\Prescription\Events\PrescriptionDeliveryRequested;
use App\Domain\Prescription\Events\PrescriptionIssued;
use App\Domain\Queue\Events\SerialApproaching;
use App\Domain\SaaS\Services\PlatformSettings;
use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Domain\Serials\Events\SerialCancelled;
use App\Domain\Serials\Events\SerialNoShow;
use App\Domain\Serials\Events\SerialPostponed;
use App\Domain\Serials\Events\SerialTransferred;
use App\Domain\Serials\Events\SessionCancelled;
use App\Domain\Serials\Events\SessionDelayed;
use App\Models\Tenant\Notification;
use App\Models\Tenant\NotificationTemplate;
use App\Models\Tenant\SmsGatewaySetting;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * The module's single provider (CONVENTIONS §12): channel drivers, the OtpSender binding the Patients module has
 * been waiting for, the event catalogue's listeners, the policies and `notifications:send-reminders`.
 *
 * Nothing here is a singleton that could hold tenant state across an Octane request: GatewayResolver memoises
 * per instance and is bound non-shared precisely so that tenant A's driver cannot survive into tenant B's request.
 */
final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SegmentCounter::class);
        $this->app->singleton(WebPushEncryptor::class);

        $this->app->singleton(VapidSigner::class, fn (): VapidSigner => new VapidSigner(
            (string) (config('notifications.push.vapid.public_key') ?? config('services.webpush.public_key') ?? ''),
            (string) (config('notifications.push.vapid.private_key') ?? config('services.webpush.private_key') ?? ''),
            (string) (config('notifications.push.vapid.subject') ?? config('services.webpush.subject') ?? ''),
        ));

        // Non-shared: a resolver caches the CURRENT tenant's gateway rows, so it must not outlive the request/job.
        // The email identity and the inherited SMS gateway are the platform's (super console settings, read at
        // resolution time); their registry defaults are config('mail.from') and "no gateway", so an install that
        // never opened the console behaves exactly as before.
        $this->app->bind(DriverFactory::class, function ($app): GatewayResolver {
            $settings = $app->make(PlatformSettings::class);
            $fromAddress = trim((string) $settings->get(PlatformSettingsRegistry::MAIL_FROM_ADDRESS));
            $fromName = trim((string) $settings->get(PlatformSettingsRegistry::MAIL_FROM_NAME));

            return new GatewayResolver(
                $app->make(SegmentCounter::class),
                $app->make(VapidSigner::class),
                $app->make(WebPushEncryptor::class),
                $app->make(Mailer::class),
                (bool) config('notifications.force_log_driver', false),
                (int) config('notifications.http_timeout', 10),
                $fromAddress !== '' ? $fromAddress : (string) config('mail.from.address', 'no-reply@example.test'),
                $fromName !== '' ? $fromName : (string) config('mail.from.name', 'Clinic'),
                (int) config('notifications.push.ttl', 3600),
                (int) config('notifications.push.prune_after_failures', 5),
                $app->bound(PlatformGatewayDefaults::class) ? $app->make(PlatformGatewayDefaults::class) : null,
            );
        });

        // ARCHITECTURE §6.3 — the Patients module's OTP delivery finally has a gateway behind it.
        $this->app->bind(OtpSender::class, SmsOtpSender::class);
    }

    public function boot(): void
    {
        Gate::policy(Notification::class, NotificationPolicy::class);
        Gate::policy(NotificationTemplate::class, NotificationTemplatePolicy::class);
        Gate::policy(SmsGatewaySetting::class, SmsGatewaySettingPolicy::class);

        $this->registerListeners();

        if ($this->app->runningInConsole()) {
            $this->commands([SendRemindersCommand::class]);
        }
    }

    /**
     * The event catalogue of BRIEF §5.J, each wired to the producer that already exists (ARCHITECTURE §5.4).
     * Every listener is queued on `notifications` except the two that only touch a row already in memory.
     */
    private function registerListeners(): void
    {
        Event::listen(AppointmentBooked::class, SendBookingConfirmation::class);          // booking confirmed
        Event::listen(SerialApproaching::class, SendThreeAheadSms::class);                // "3 patients ahead"
        Event::listen(SessionDelayed::class, FanOutSessionDelay::class);                  // doctor delayed
        Event::listen(SessionCancelled::class, FanOutSessionCancellation::class);         // doctor cancelled (fan-out)
        Event::listen(PrescriptionIssued::class, SendPrescriptionReady::class);           // prescription ready
        Event::listen(PrescriptionDeliveryRequested::class, DeliverPrescription::class);  // explicit send
        Event::listen(PdfReady::class, AttachPdfOnReady::class);
        Event::listen(FollowUpScheduled::class, ScheduleFollowUpReminder::class);         // follow-up due
        Event::listen(SerialTransferred::class, NotifySerialMoved::class);
        Event::listen(SerialPostponed::class, NotifySerialMoved::class);
        Event::listen(SerialCancelled::class, CancelPendingNotifications::class);
        Event::listen(SerialNoShow::class, CancelPendingNotifications::class);
        Event::listen(NotificationSent::class, RecordPrescriptionDelivery::class);
    }
}
