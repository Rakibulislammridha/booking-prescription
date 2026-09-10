<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Tenants;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Clinic\Enums\Locale;
use App\Domain\Clinic\Services\ClinicUploads;
use App\Domain\SaaS\Data\TenantProfileData;
use App\Domain\SaaS\Services\CentralAudit;
use App\Models\Central\Tenant;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The console's edit of a clinic's identity (SCHEMA §2.1). Branding is MERGED, never replaced, so the keys the
 * clinic's own settings screen owns (`on_primary_color`, taglines, favicon) survive an operator's save; the logo
 * goes through the same `ClinicUploads::brandingLogo()` the clinic uses, inside `Tenancy::run()` so `TenantPath`
 * puts it under `tenants/{id}/…` exactly as if the clinic had uploaded it.
 *
 * The audit row carries the CHANGED attributes only (SCHEMA §2.13), which is what makes "who changed the owner
 * e-mail and when" answerable without diffing two blobs.
 */
final class UpdateTenantProfile
{
    private const TRACKED = ['name', 'owner_name', 'owner_email', 'owner_mobile', 'locale', 'timezone', 'platform_notes'];

    private const BRANDING = ['name_bn', 'primary_color', 'accent_color', 'logo_path'];

    public function __construct(
        private readonly ClinicUploads $uploads,
        private readonly CentralAudit $audit,
    ) {}

    public function handle(Tenant $tenant, TenantProfileData $data, ?UploadedFile $logo = null, ?int $superAdminId = null): Tenant
    {
        $branding = $tenant->branding;
        $previousLogo = is_string($branding['logo_path'] ?? null) ? $branding['logo_path'] : null;

        $logoPath = $logo === null ? null : Tenancy::run($tenant, fn (): string => $this->uploads->brandingLogo($logo));

        $nextBranding = array_replace($branding, [
            'name_bn' => $data->nameBn,
            'primary_color' => $data->primaryColor,
            'accent_color' => $data->accentColor,
            'logo_path' => $data->clearLogo ? null : ($logoPath ?? $previousLogo),
        ]);

        $before = $this->snapshot($tenant, $branding);

        $tenant->fill([
            'name' => $data->name,
            'owner_name' => $data->ownerName,
            'owner_email' => $data->ownerEmail,
            'owner_mobile' => $data->ownerMobile,
            'locale' => Locale::from($data->locale),
            'timezone' => $data->timezone,
            'platform_notes' => $data->notes,
            'branding' => $nextBranding,
        ])->save();

        $after = $this->snapshot($tenant, $nextBranding);
        $changed = array_keys(array_filter($after, fn ($value, $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed !== []) {
            $this->audit->record(
                CentralAuditAction::Update,
                $tenant,
                $tenant,
                array_intersect_key($before, array_flip($changed)),
                array_intersect_key($after, array_flip($changed)),
                $superAdminId,
            );
        }

        if ($previousLogo !== null && $previousLogo !== ($nextBranding['logo_path'] ?? null)) {
            $this->forget($previousLogo);
        }

        return $tenant->refresh();
    }

    /**
     * @param  array<string, mixed>  $branding
     * @return array<string, mixed>
     */
    private function snapshot(Tenant $tenant, array $branding): array
    {
        $out = [];

        foreach (self::TRACKED as $column) {
            $value = $tenant->getAttribute($column);
            $out[$column] = $value instanceof Locale ? $value->value : $value;
        }

        foreach (self::BRANDING as $key) {
            $out['branding.'.$key] = $branding[$key] ?? null;
        }

        return $out;
    }

    private function forget(string $path): void
    {
        try {
            Storage::disk($this->uploads->publicDisk())->delete($path);
        } catch (Throwable $e) {
            report($e);                                              // a stale file is not worth failing the save
        }
    }
}
