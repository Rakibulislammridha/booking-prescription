<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Notifications\Enums\NotificationLogStatus;
use App\Models\Tenant\Notification;
use App\Models\Tenant\NotificationLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationLog> */
final class NotificationLogFactory extends Factory
{
    protected $model = NotificationLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'notification_id' => Notification::factory(),
            'attempt_no' => 1,
            'provider' => 'log',
            'provider_message_id' => null,
            'status' => NotificationLogStatus::Sent,
            'request' => ['to' => '+88017*****678'],
            'response' => ['ok' => true],
            'error_code' => null,
            'latency_ms' => 12,
        ];
    }

    public function rejected(string $code = 'invalid_number'): static
    {
        return $this->state(fn () => ['status' => NotificationLogStatus::Rejected, 'error_code' => $code, 'response' => ['error' => $code]]);
    }
}
