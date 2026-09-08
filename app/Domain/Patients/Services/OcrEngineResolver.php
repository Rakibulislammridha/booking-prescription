<?php

declare(strict_types=1);

namespace App\Domain\Patients\Services;

use App\Domain\Patients\Contracts\OcrEngine;

/**
 * Picks the engine for the CURRENT tenant (PRESCRIPTION.md §8). It exists so the binding stays the null engine
 * unless a driver AND a key are actually present: a half-configured clinic gets today's behaviour rather than a
 * queue full of 401s, and the test suite — where nothing is configured — can never reach the network.
 */
final class OcrEngineResolver
{
    public function __construct(private readonly OcrSettings $settings) {}

    public function resolve(): OcrEngine
    {
        $key = $this->settings->apiKey();

        if ($this->settings->driver() !== 'google' || $key === '') {
            return new NullOcrEngine;
        }

        return new GoogleVisionOcrEngine($key, $this->settings->endpoint(), $this->settings->timeout());
    }
}
