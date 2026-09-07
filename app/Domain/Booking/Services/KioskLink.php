<?php

declare(strict_types=1);

namespace App\Domain\Booking\Services;

use App\Models\Tenant\Branch;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\URL;

/** SERIAL_ENGINE §11.2: the signed, short-lived per-branch (and optionally per-session) QR URL the desk shows. */
final class KioskLink
{
    public const HOURS = 12;

    public function url(Branch $branch, ?SessionInstance $session = null): string
    {
        return URL::temporarySignedRoute('site.booking.kiosk', now()->addHours(self::HOURS), array_filter([
            'branch' => $branch->public_id,
            'session' => $session?->public_id,
        ]));
    }
}
