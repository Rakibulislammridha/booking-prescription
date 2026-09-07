<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Scheduling;

use App\Domain\Scheduling\Data\ScheduleOverrideData;
use App\Domain\Scheduling\Enums\OverrideType;
use App\Models\Tenant\ScheduleOverride;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreScheduleOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('web');
        $doctorId = $this->integer('doctor_id') ?: null;

        return $user instanceof User && $user->can('create', [ScheduleOverride::class, $doctorId]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'doctor_id' => ['required', 'integer', Rule::exists('doctors', 'id')->whereNull('deleted_at')],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'override_date' => ['required', 'date_format:Y-m-d'],
            'session_code' => ['nullable', 'string', 'regex:/^[A-Za-z]$/'],
            'type' => ['required', Rule::enum(OverrideType::class)],
            'delay_minutes' => ['nullable', 'integer', 'between:0,600', 'required_if:type,late_start'],
            'new_start_time' => ['nullable', 'date_format:H:i', 'required_if:type,time_change,extra_session'],
            'new_end_time' => ['nullable', 'date_format:H:i', 'required_if:type,time_change,extra_session,cut_short'],
            'new_counter_quota' => ['nullable', 'integer', 'between:0,999', 'required_if:type,capacity_change,extra_session'],
            'new_online_quota' => ['nullable', 'integer', 'between:0,999', 'required_if:type,capacity_change,extra_session'],
            'new_buffer_quota' => ['nullable', 'integer', 'between:0,999', 'required_if:type,capacity_change,extra_session'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notify_patients' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): ScheduleOverrideData
    {
        return ScheduleOverrideData::fromRequest($this);
    }
}
