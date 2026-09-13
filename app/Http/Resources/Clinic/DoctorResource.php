<?php

declare(strict_types=1);

namespace App\Http\Resources\Clinic;

use App\Models\Tenant\Doctor;
use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Doctor */
final class DoctorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user('web');

        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'name_bn' => $this->name_bn,
            'slug' => $this->slug,
            'code' => $this->code,
            'gender' => $this->gender?->value,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'department_id' => $this->department_id,
            'department_name' => $this->whenLoaded('department', fn () => $this->department?->name),
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'sort_order' => $this->sort_order,
            'photo_path' => $this->photo_path,
            'photo_url' => $this->photo_path === null ? null : route('panel.clinic.doctors.photo.show', ['doctor' => $this->public_id]),
            'is_active' => $this->is_active,
            'accepts_online_booking' => $this->accepts_online_booking,
            'accepts_telemedicine' => $this->accepts_telemedicine,
            'room_label' => $this->room_label,
            // Row-level abilities, because both rules are "an admin administers every row, a doctor administers
            // their own" (DoctorPolicy::designPad / ::manageCompounders) — a per-row question a screen holding one
            // `can.manage` flag cannot answer. The roster shipped mirroring that policy in TypeScript for the
            // compounders link and rendering the Pad button with no gate at all, so a doctor clicking a colleague's
            // pad got a 403 from a button the page had drawn for them. Two Gate calls per row and no query in
            // either: designPad reads the cached permission set plus one integer, manageCompounders the same.
            'can_design_pad' => $user instanceof User && $user->can('designPad', $this->resource),
            'can_manage_compounders' => $user instanceof User && $user->can('manageCompounders', $this->resource),
            'profile' => $this->whenLoaded('profile', fn () => $this->profile === null ? null : [
                'degrees' => $this->profile->degrees,
                'degrees_bn' => $this->profile->degrees_bn,
                'bmdc_reg_no' => $this->profile->bmdc_reg_no,
                'designation' => $this->profile->designation,
                'new_fee_paisa' => $this->profile->new_fee_paisa,
                'followup_fee_paisa' => $this->profile->followup_fee_paisa,
                'free_followup_within_days' => $this->profile->free_followup_within_days,
                'followup_within_days' => $this->profile->followup_within_days,
                'bio' => $this->profile->bio,
                'bio_bn' => $this->profile->bio_bn,
                'experience_years' => $this->profile->experience_years,
                'languages' => $this->profile->languages,
                'report_visit_free' => $this->profile->report_visit_free,
                'telemedicine_fee_paisa' => $this->profile->telemedicine_fee_paisa,
                'online_booking_fee_delta_paisa' => $this->profile->online_booking_fee_delta_paisa,
                'advance_payment_required' => $this->profile->advance_payment_required,
                'chamber_notes' => $this->profile->chamber_notes,
            ]),
            'specialty_ids' => $this->whenLoaded('doctorSpecialties', fn () => $this->doctorSpecialties->pluck('specialty_id')->map(fn ($id) => (int) $id)->values()->all()),
            'primary_specialty_id' => $this->whenLoaded('doctorSpecialties', fn () => $this->doctorSpecialties->firstWhere('is_primary', true)?->specialty_id),
            // Resolved: a nested resource collection reaches an Inertia page as `{data: [...]}` (see PatientResource).
            'specialties' => $this->whenLoaded('specialties', fn () => SpecialtyResource::collection($this->specialties)->resolve($request)),
        ];
    }
}
