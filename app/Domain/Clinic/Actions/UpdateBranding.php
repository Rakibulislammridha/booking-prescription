<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Clinic\Data\BrandingData;
use App\Domain\Clinic\Enums\Locale;
use App\Domain\Shared\Actor;
use App\Models\Central\Tenant;

/**
 * Writes the clinic's public identity onto its central row (SCHEMA §2.1). `branding` is merged, never replaced, so
 * keys this screen does not own (favicon_path, tagline_bn/en) survive a save.
 */
final class UpdateBranding
{
    public function handle(Tenant $tenant, BrandingData $data, Actor $actor): Tenant
    {
        $tenant->fill([
            'name' => $data->name,
            'locale' => Locale::from($data->locale),
            'branding' => $data->toBranding($tenant->branding),
        ])->save();

        return $tenant->refresh();
    }
}
