<?php

declare(strict_types=1);

namespace App\Domain\Prescription\AI;

use App\Tenancy\Facades\Tenancy;
use Laravel\Pennant\Feature;

/** AI assist is on for a tenant when services.ai.key is set AND the Pennant feature `ai-assist` is active for it. */
final class AiGate
{
    public function __construct(private readonly AiAssistant $assistant) {}

    public function enabled(): bool
    {
        $tenant = Tenancy::current();

        if ($tenant === null || ! $this->assistant->isAvailable()) {
            return false;
        }

        try {
            return (bool) Feature::for($tenant)->active(self::FEATURE);
        } catch (\Throwable) {
            return false;
        }
    }

    public const FEATURE = 'ai-assist';
}
