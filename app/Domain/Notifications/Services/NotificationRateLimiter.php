<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Cache\RateLimiter;

/**
 * Per-tenant, per-channel send budget. Gateways are shared infrastructure: one clinic broadcasting a delay to 400
 * waiting patients must not consume the account another clinic needs to send an OTP. Exceeding the budget releases
 * the job back to the `notifications` queue instead of failing it, so nothing is lost — only slowed.
 */
final class NotificationRateLimiter
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function attempt(NotificationChannel $channel): bool
    {
        $max = $this->max();

        if ($max <= 0) {
            return true;
        }

        $key = $this->key($channel);

        if ($this->limiter->tooManyAttempts($key, $max)) {
            return false;
        }

        $this->limiter->hit($key, 60);

        return true;
    }

    public function availableIn(NotificationChannel $channel): int
    {
        return max(1, $this->limiter->availableIn($this->key($channel)) ?: (int) config('notifications.rate_limit.retry_after', 60));
    }

    public function remaining(NotificationChannel $channel): int
    {
        $max = $this->max();

        return $max <= 0 ? PHP_INT_MAX : $this->limiter->remaining($this->key($channel), $max);
    }

    public function clear(NotificationChannel $channel): void
    {
        $this->limiter->clear($this->key($channel));
    }

    private function key(NotificationChannel $channel): string
    {
        return 'notifications:'.(Tenancy::id() ?? 'central').':'.$channel->value;
    }

    private function max(): int
    {
        return (int) config('notifications.rate_limit.per_minute', 300);
    }
}
