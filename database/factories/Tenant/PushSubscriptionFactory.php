<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Patient;
use App\Models\Tenant\PushSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PushSubscription> */
final class PushSubscriptionFactory extends Factory
{
    protected $model = PushSubscription::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $endpoint = 'https://push.example.test/'.$this->faker->unique()->uuid();

        return [
            'subscriber_type' => Patient::class,
            'subscriber_id' => Patient::factory(),
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hash($endpoint),
            'keys' => ['p256dh' => str_repeat('a', 87), 'auth' => str_repeat('b', 22)],
            'content_encoding' => 'aes128gcm',
            'user_agent' => 'PHPUnit',
            'failed_count' => 0,
        ];
    }

    public function forSubscriber(string $type, int $id): static
    {
        return $this->state(fn () => ['subscriber_type' => $type, 'subscriber_id' => $id]);
    }
}
