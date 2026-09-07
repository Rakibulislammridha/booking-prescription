<?php

declare(strict_types=1);

namespace App\Domain\Reception\Actions;

use App\Domain\Serials\Actions\RevokeBlock as EngineRevokeBlock;
use App\Domain\Shared\Actor;
use App\Models\Tenant\SerialBlock;

/** OFFLINE §4.5 (Hospital Admin): the same row keeps the unissued numbers as a revoked free-list entry. */
final class RevokeBlock
{
    public function __construct(private readonly EngineRevokeBlock $engine) {}

    public function handle(SerialBlock $block, Actor $actor, ?string $reason = null): SerialBlock
    {
        return $this->engine->handle($block, $actor, $reason);
    }
}
