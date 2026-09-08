<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Services;

use App\Models\Central\Domain;
use App\Models\Tenant\TelemedicineRoom;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The patient's signed, expiring join link (BRIEF §5.K/§5.J).
 *
 * It is signed RELATIVELY and the tenant host is prefixed afterwards. That is deliberate: a clinic can be
 * reached on `sheba.bp.app` today and on its own `sheba.com.bd` tomorrow (SCHEMA §2.7 custom domains), and an
 * absolute signature would break the moment a patient followed the link on the other host — while a relative one
 * is verified against `/telemedicine/j/{room}?expires=…` alone. The room name already carries 128 bits of ULID,
 * so the signature is defence in depth over a value that was never guessable to begin with.
 *
 * The window is anchored on the appointment: valid from `valid_before_minutes` ahead of the planned start until
 * `valid_after_minutes` after it — long enough for a clinic running late, short enough that yesterday's SMS is
 * dead. The link only ever grants the right to ASK for a token; the media credential itself is minted per join
 * with a 15-minute TTL and never travels over SMS.
 */
final class JoinLink
{
    public function for(TelemedicineRoom $room): string
    {
        return $this->host().$this->relative($room);
    }

    public function relative(TelemedicineRoom $room): string
    {
        return URL::temporarySignedRoute('site.telemedicine.join', $this->expiresAt($room), ['room' => $room->room_name], absolute: false);
    }

    public function expiresAt(TelemedicineRoom $room): CarbonImmutable
    {
        $after = (int) config('telemedicine.link.valid_after_minutes', 240);
        $anchored = $room->scheduled_at->addMinutes($after);
        $floor = CarbonImmutable::now()->addMinutes(30);

        return $anchored->greaterThan($floor) ? $anchored : $floor;
    }

    public function isValid(Request $request): bool
    {
        return URL::hasValidRelativeSignature($request);
    }

    /** `https://{primary domain}` or `{scheme}://{slug}.{central}` — the same rule as the prescription QR URL. */
    public function host(): string
    {
        $tenant = Tenancy::current();
        $host = null;

        if ($tenant !== null) {
            $primary = Domain::query()->where('tenant_id', $tenant->id)->where('is_primary', true)->value('domain');
            $host = is_string($primary) && $primary !== '' ? $primary : $tenant->slug.'.'.config('tenancy.central_domain');
        }

        $scheme = app()->environment('production') || str_starts_with((string) config('app.url'), 'https') ? 'https' : 'http';

        return $scheme.'://'.($host ?? parse_url((string) config('app.url'), PHP_URL_HOST) ?? 'localhost');
    }
}
