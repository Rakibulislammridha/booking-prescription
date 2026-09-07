<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications\Concerns;

use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Contracts\DriverFactory;
use App\Domain\Notifications\Data\GatewayConfig;
use App\Domain\Notifications\Drivers\LogChannelDriver;
use App\Domain\Notifications\Enums\GatewayProvider;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Patients\Enums\ConsentStatus;
use App\Domain\Patients\Enums\ConsentType;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientConsent;
use App\Models\Tenant\SmsGatewaySetting;
use Tests\Feature\Queue\Concerns\QueueFixtures;

/**
 * Fixtures for the Notifications suite. Builds on the Queue module's session/serial helpers (composing beats
 * re-implementing the serial engine in a second place) and adds gateway rows, consent rows and a recording driver.
 */
trait NotificationFixtures
{
    use QueueFixtures;

    /** @param  array<string, mixed>  $attributes */
    protected function patient(array $attributes = []): Patient
    {
        return Patient::factory()->create([
            'mobile' => '+8801712345678',
            'preferred_language' => 'bn',
            ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function smsGateway(array $attributes = []): SmsGatewaySetting
    {
        return SmsGatewaySetting::factory()->create($attributes);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function whatsappGateway(array $attributes = []): SmsGatewaySetting
    {
        return SmsGatewaySetting::factory()->whatsapp()->create($attributes);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function ivrGateway(array $attributes = []): SmsGatewaySetting
    {
        return SmsGatewaySetting::factory()->ivr()->create($attributes);
    }

    protected function revokeConsent(Patient $patient, ConsentType $type = ConsentType::Sms): PatientConsent
    {
        return PatientConsent::factory()->create(['patient_id' => $patient->id, 'type' => $type, 'status' => ConsentStatus::Revoked]);
    }

    protected function grantConsent(Patient $patient, ConsentType $type = ConsentType::Sms): PatientConsent
    {
        return PatientConsent::factory()->create(['patient_id' => $patient->id, 'type' => $type, 'status' => ConsentStatus::Granted]);
    }

    /**
     * Bind a driver that records what it was asked to send, for every channel, so a test can assert on the
     * rendered body without a gateway. Returns the recorder.
     */
    protected function recordingDriver(): LogChannelDriver
    {
        $recorder = new LogChannelDriver(NotificationChannel::Sms, writeLog: false);

        $this->app->bind(DriverFactory::class, fn (): DriverFactory => new class($recorder) implements DriverFactory
        {
            public function __construct(private readonly LogChannelDriver $recorder) {}

            public function for(NotificationChannel $channel): ChannelDriver
            {
                return $this->recorder;
            }

            public function fromConfig(GatewayConfig $config): ChannelDriver
            {
                return $this->recorder;
            }

            public function configFor(NotificationChannel $channel): ?GatewayConfig
            {
                return null;
            }
        });

        return $recorder;
    }

    /** Bind a driver that always answers with the given result — the retry/dead-letter tests. */
    protected function bindDriver(ChannelDriver $driver): void
    {
        $this->app->bind(DriverFactory::class, fn (): DriverFactory => new class($driver) implements DriverFactory
        {
            public function __construct(private readonly ChannelDriver $driver) {}

            public function for(NotificationChannel $channel): ChannelDriver
            {
                return $this->driver;
            }

            public function fromConfig(GatewayConfig $config): ChannelDriver
            {
                return $this->driver;
            }

            public function configFor(NotificationChannel $channel): ?GatewayConfig
            {
                return null;
            }
        });
    }

    /** @return array<int, Notification> the ledger, oldest first */
    protected function notifications(?string $eventKey = null): array
    {
        return Notification::query()
            ->when($eventKey !== null, fn ($q) => $q->where('event_key', $eventKey))
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** @return array<int, string> */
    protected function gatewayProviders(): array
    {
        return GatewayProvider::values();
    }
}
