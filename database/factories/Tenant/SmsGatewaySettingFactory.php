<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Notifications\Enums\GatewayProvider;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Models\Tenant\SmsGatewaySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SmsGatewaySetting> */
final class SmsGatewaySettingFactory extends Factory
{
    protected $model = SmsGatewaySetting::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'channel' => NotificationChannel::Sms,
            'provider' => GatewayProvider::SslWireless,
            'name' => 'Primary SMS',
            'sender_id' => 'CLINIC',
            'credentials' => ['api_token' => 'test-token', 'sid' => 'TESTSID', 'url' => 'https://smsplus.example.test/api/v3/send-sms'],
            'options' => ['unicode' => true],
            'priority' => 10,
            'is_default' => true,
            'is_active' => true,
        ];
    }

    public function whatsapp(): static
    {
        return $this->state(fn () => [
            'channel' => NotificationChannel::Whatsapp,
            'provider' => GatewayProvider::WhatsappCloud,
            'name' => 'WhatsApp Cloud',
            'sender_id' => '10000000001',
            'credentials' => ['access_token' => 'test-wa-token', 'phone_number_id' => '10000000001'],
        ]);
    }

    public function ivr(): static
    {
        return $this->state(fn () => [
            'channel' => NotificationChannel::Ivr,
            'provider' => GatewayProvider::CustomHttp,
            'name' => 'IVR trunk',
            'sender_id' => '09600000000',
            'credentials' => ['url' => 'https://ivr.example.test/call', 'api_key' => 'test-ivr-key'],
        ]);
    }

    public function customHttp(): static
    {
        return $this->state(fn () => [
            'provider' => GatewayProvider::CustomHttp,
            'name' => 'Generic HTTP SMS',
            'credentials' => ['url' => 'https://sms.example.test/send', 'api_key' => 'test-key'],
            'options' => ['unicode' => true, 'method' => 'POST', 'body_field' => 'message', 'to_field' => 'to'],
        ]);
    }

    public function secondary(): static
    {
        return $this->state(fn () => ['name' => 'Backup SMS', 'provider' => GatewayProvider::BulkSmsBd, 'is_default' => false, 'priority' => 20]);
    }
}
