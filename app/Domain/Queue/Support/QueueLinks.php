<?php

declare(strict_types=1);

namespace App\Domain\Queue\Support;

/**
 * The public queue page URL (REALTIME.md §5.3): `/q/{doctorSlug}/today?s={serialPublicId}`. `s` is the serial's
 * public id — the page pins that serial and shows "your serial · N ahead · estimated HH:MM". One builder for the
 * booking confirmation, the public API, the telemedicine receipt and the patient portal, so the link the patient
 * follows is the same one whichever screen they came from.
 */
final class QueueLinks
{
    /** Path-relative (the tenant host is the caller's); without a serial the page shows the board only. */
    public static function forSerial(string $doctorSlug, ?string $serialPublicId): string
    {
        $path = route('site.queue.page', ['doctorSlug' => $doctorSlug], absolute: false);

        return $serialPublicId === null || $serialPublicId === '' ? $path : $path.'?s='.rawurlencode($serialPublicId);
    }
}
