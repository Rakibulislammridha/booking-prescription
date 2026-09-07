<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Serials\Events\BlockRevoked;
use App\Domain\Shared\Actor;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SessionInstance;

/**
 * OFFLINE §4.5 (device lost / stolen / replaced): the same row keeps owning its unissued numbers as a free-list
 * entry, with revoked_at set — "revoked" = released AND revoked_at IS NOT NULL; no second row, so the exclusion
 * constraint is never challenged.
 */
final class RevokeBlock
{
    public function __construct(private readonly ReleaseBlock $release) {}

    public function handle(SerialBlock $block, Actor $actor, ?string $reason = null): SerialBlock
    {
        $revoked = $this->release->handle($block, $actor, $reason ?? 'revoked', revoke: true);

        /** @var SessionInstance $session */
        $session = SessionInstance::query()->findOrFail($revoked->session_instance_id);
        BlockRevoked::dispatch($session, $actor, ['block_id' => $revoked->id, 'range' => [$revoked->range_start, $revoked->range_end], 'returned' => $revoked->returned_count]);

        return $revoked;
    }
}
