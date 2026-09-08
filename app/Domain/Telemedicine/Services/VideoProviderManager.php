<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Services;

use App\Domain\Telemedicine\Contracts\VideoProvider;
use App\Domain\Telemedicine\Enums\TelemedicineProvider;
use App\Domain\Telemedicine\Providers\JitsiProvider;
use App\Domain\Telemedicine\Providers\LiveKitProvider;
use App\Domain\Telemedicine\Providers\NullVideoProvider;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Resolves THE driver for the current tenant. Bound NOT-shared in the service provider: a resolver caches one
 * clinic's credentials, so it must not survive into another clinic's Octane request (the same reasoning that
 * shapes Notifications' GatewayResolver).
 *
 * The rule that keeps the suite offline and a half-configured clinic safe: a driver that reports
 * `isConfigured() === false` is replaced by the null driver rather than being called and failing.
 */
final class VideoProviderManager
{
    private ?VideoProvider $resolved = null;

    public function __construct(
        private readonly TelemedicineSettings $settings,
        private readonly HttpFactory $http,
    ) {}

    public function driver(): VideoProvider
    {
        return $this->resolved ??= $this->resolve();
    }

    public function forget(): void
    {
        $this->resolved = null;
    }

    private function resolve(): VideoProvider
    {
        $name = $this->settings->driverName();

        if ($name === 'null') {
            return $this->null();
        }

        $credentials = $this->settings->credentials();
        $timeout = (int) config('telemedicine.http_timeout', 10);

        $driver = match ($name) {
            'livekit' => new LiveKitProvider($credentials->withProvider(TelemedicineProvider::Livekit), $this->http, $timeout),
            'jitsi' => new JitsiProvider($credentials->withProvider(TelemedicineProvider::Jitsi)),
            // Agora is named by BRIEF §5.K but ships unimplemented: its token format is a binary packing, not a
            // JWT, and nothing in the product needs it while LiveKit and Jitsi cover paid and self-hosted.
            default => null,
        };

        return $driver !== null && $driver->isConfigured() ? $driver : $this->null();
    }

    private function null(): NullVideoProvider
    {
        return new NullVideoProvider(
            secret: (string) config('app.key', 'bp-telemedicine'),
            recordsAs: TelemedicineProvider::from((string) config('telemedicine.null_driver_records_as', 'jitsi')),
        );
    }
}
