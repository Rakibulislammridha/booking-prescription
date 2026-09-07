<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Clinic\Data\PadSettingsData;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorPadSetting;

/**
 * Pad designer save: partial update over the SCHEMA defaults. Print-on-preprinted-pad mode blanks the letterhead.
 */
final class UpdateDoctorPadSettings
{
    public function handle(Doctor $doctor, PadSettingsData $data, Actor $actor): DoctorPadSetting
    {
        /** @var DoctorPadSetting $setting */
        $setting = DoctorPadSetting::query()->firstOrNew(['doctor_id' => $doctor->id], DoctorPadSetting::defaults());

        $attributes = $data->attributes;

        if (($attributes['preprinted_mode'] ?? $setting->preprinted_mode) === true) {
            $attributes['letterhead_enabled'] = false;
        }

        $setting->fill($attributes)->save();

        return $setting;
    }
}
