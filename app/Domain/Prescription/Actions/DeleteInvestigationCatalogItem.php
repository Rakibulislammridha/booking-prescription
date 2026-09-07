<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Shared\Actor;
use App\Models\Tenant\InvestigationCatalogItem;

/** Deactivates: prescription_investigations keep their snapshot name/price. */
final class DeleteInvestigationCatalogItem
{
    public function handle(InvestigationCatalogItem $item, Actor $actor): void
    {
        $item->forceFill(['is_active' => false])->save();
    }
}
