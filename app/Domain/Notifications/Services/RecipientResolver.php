<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\Notifications\Data\NotificationRequest;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Patients\Services\MobileNumber;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PushSubscription;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;

/**
 * Turns "notify this patient on this channel" into concrete addresses. Push is the interesting case: one person may
 * have several browsers subscribed, so the resolver returns ONE address per subscription and the dispatcher writes
 * one `notifications` row for each — a per-endpoint dedupe suffix keeps the ledger honest about what went where.
 */
final class RecipientResolver
{
    /** @return array<int, string> zero or more addresses for the request's channel */
    public function resolve(NotificationRequest $request): array
    {
        if ($request->recipient !== null && trim($request->recipient) !== '') {
            return [$this->normalize(trim($request->recipient), $request->channel)];
        }

        return match ($request->channel) {
            NotificationChannel::Email => array_filter([$request->patient->email ?? $request->user->email ?? null]),
            NotificationChannel::Push => $this->pushEndpoints($request),
            default => array_filter([$request->patient->mobile ?? $request->user->mobile ?? null]),
        };
    }

    /**
     * An explicitly supplied address is normalised for its channel: a receptionist typing `01711111111` and the
     * patient record's `+8801711111111` must produce the SAME ledger row, or the log becomes two people.
     */
    private function normalize(string $recipient, NotificationChannel $channel): string
    {
        return $channel->recipientKind() === 'mobile' ? (MobileNumber::tryNormalize($recipient) ?? $recipient) : $recipient;
    }

    public function locale(NotificationRequest $request): Locale
    {
        if ($request->locale instanceof Locale) {
            return $request->locale;
        }

        if ($request->patient !== null) {
            return $request->patient->preferred_language;
        }

        if ($request->user !== null) {
            return $request->user->locale;
        }

        $tenantLocale = Tenancy::current()?->locale;

        return $tenantLocale instanceof Locale ? $tenantLocale : Locale::Bn;
    }

    /** @return array<int, string> */
    private function pushEndpoints(NotificationRequest $request): array
    {
        [$type, $id] = match (true) {
            $request->patient instanceof Patient => [Patient::class, $request->patient->id],
            $request->user instanceof User => [User::class, $request->user->id],
            default => [null, null],
        };

        if ($type === null || $id === null) {
            return [];
        }

        return PushSubscription::query()
            ->where('subscriber_type', $type)
            ->where('subscriber_id', $id)
            ->orderBy('id')
            ->pluck('endpoint')
            ->map(fn (mixed $endpoint): string => (string) $endpoint)
            ->all();
    }
}
