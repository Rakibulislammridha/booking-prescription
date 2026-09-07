<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Shared\Actor;
use App\Models\Tenant\AdviceSnippet;

/** Deactivates (prescription_advice rows keep their snapshot text; the FK is SET NULL on a hard delete anyway). */
final class DeleteAdviceSnippet
{
    public function handle(AdviceSnippet $snippet, Actor $actor): void
    {
        $snippet->forceFill(['is_active' => false])->save();
    }
}
