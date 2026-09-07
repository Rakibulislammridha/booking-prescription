<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Shared\Actor;
use App\Models\Tenant\InvestigationCatalogItem;

/** Create / update a clinic test with its price (PRESCRIPTION.md §4.5). */
final class SaveInvestigationCatalogItem
{
    /** @param  array<string, mixed>  $data  validated request body */
    public function handle(array $data, Actor $actor, ?InvestigationCatalogItem $existing = null): InvestigationCatalogItem
    {
        $item = $existing ?? new InvestigationCatalogItem;
        $item->fill(array_intersect_key($data, array_flip(['branch_id', 'code', 'name', 'name_bn', 'category', 'price_paisa', 'prep_instructions', 'prep_instructions_bn', 'is_active', 'sort_order'])));
        $item->save();

        return $item;
    }
}
