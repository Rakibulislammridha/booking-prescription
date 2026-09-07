<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;

/** The doctor screen's "skip" — the same edge as ReturnToQueue with reason `skipped` (SERIAL_ENGINE §14). */
final class SkipCalled
{
    public function __construct(private readonly ReturnToQueue $returnToQueue) {}

    public function handle(Serial $serial, Actor $actor): Serial
    {
        return $this->returnToQueue->handle($serial, $actor, 'skipped');
    }
}
