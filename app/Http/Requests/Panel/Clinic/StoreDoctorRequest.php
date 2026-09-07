<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Domain\Clinic\Data\DoctorData;
use App\Domain\Clinic\Enums\Gender;
use App\Models\Tenant\Doctor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDoctorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', Doctor::class) ?? false;
    }

    /**
     * The create screen may also create the doctor's staff login in the same submit (`new_user`), which is how a
     * clinic adds a doctor who will write prescriptions. `user_id` and `new_user` are mutually exclusive.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return self::doctorRules(null) + [
            'new_user' => ['nullable', 'array', 'prohibits:user_id'],
            'new_user.name' => ['required_with:new_user', 'string', 'max:160'],
            'new_user.email' => ['required_with:new_user', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'new_user.mobile' => ['nullable', 'string', 'regex:/^\+8801[3-9]\d{8}$/', Rule::unique('users', 'mobile')->whereNull('deleted_at')],
            'new_user.default_branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'new_user.locale' => ['sometimes', Rule::in(['bn', 'en'])],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function doctorRules(?int $ignoreId): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'name_bn' => ['nullable', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/', Rule::unique('doctors', 'slug')->ignore($ignoreId)->whereNull('deleted_at')],
            'code' => ['required', 'string', 'max:8', 'alpha_num:ascii', Rule::unique('doctors', 'code')->ignore($ignoreId)->whereNull('deleted_at')],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'mobile' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'room_label' => ['nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
            'accepts_online_booking' => ['sometimes', 'boolean'],
            'accepts_telemedicine' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,32767'],
            'specialty_ids' => ['sometimes', 'array'],
            'specialty_ids.*' => ['integer', Rule::exists('specialties', 'id')],
            'primary_specialty_id' => ['nullable', 'integer'],
            'profile' => ['sometimes', 'array'],
            'profile.degrees' => ['nullable', 'string', 'max:255'],
            'profile.degrees_bn' => ['nullable', 'string', 'max:255'],
            'profile.bmdc_reg_no' => ['nullable', 'string', 'max:32'],
            'profile.designation' => ['nullable', 'string', 'max:160'],
            'profile.bio' => ['nullable', 'string'],
            'profile.bio_bn' => ['nullable', 'string'],
            'profile.experience_years' => ['nullable', 'integer', 'between:0,80'],
            'profile.languages' => ['sometimes', 'array'],
            'profile.languages.*' => ['string', Rule::in(['bn', 'en'])],
            'profile.new_fee_paisa' => ['sometimes', 'integer', 'min:0'],
            'profile.followup_fee_paisa' => ['sometimes', 'integer', 'min:0'],
            'profile.free_followup_within_days' => ['sometimes', 'integer', 'min:0', 'lte:profile.followup_within_days'],
            'profile.followup_within_days' => ['sometimes', 'integer', 'min:0'],
            'profile.report_visit_free' => ['sometimes', 'boolean'],
            'profile.telemedicine_fee_paisa' => ['nullable', 'integer', 'min:0'],
            'profile.online_booking_fee_delta_paisa' => ['sometimes', 'integer'],
            'profile.advance_payment_required' => ['sometimes', 'boolean'],
            'profile.chamber_notes' => ['nullable', 'string'],
        ];
    }

    public function toData(): DoctorData
    {
        return DoctorData::fromRequest($this);
    }
}
