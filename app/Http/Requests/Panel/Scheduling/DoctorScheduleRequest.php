<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Scheduling;

use App\Domain\Scheduling\Data\DoctorScheduleData;
use App\Domain\Scheduling\Enums\ScheduleMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Rules shared by the store and update requests of a weekly template row. */
abstract class DoctorScheduleRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'doctor_id' => ['required', 'integer', Rule::exists('doctors', 'id')->whereNull('deleted_at')],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'weekday' => ['required', 'integer', 'between:0,6'],
            'session_code' => ['required', 'string', 'regex:/^[A-Za-z]$/'],
            'session_label' => ['nullable', 'string', 'max:32'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'mode' => ['sometimes', Rule::enum(ScheduleMode::class)],
            'slot_minutes' => ['nullable', 'integer', 'between:5,120', 'required_if:mode,slot'],
            'counter_quota' => ['required', 'integer', 'between:0,999'],
            'online_quota' => ['required', 'integer', 'between:0,999'],
            'buffer_quota' => ['sometimes', 'integer', 'between:0,999'],
            'avg_consult_minutes' => ['sometimes', 'integer', 'between:1,120'],
            'fee_new_paisa' => ['nullable', 'integer', 'min:0'],
            'fee_followup_paisa' => ['nullable', 'integer', 'min:0'],
            'auto_noshow_after' => ['nullable', 'integer', 'between:0,20'],
            'works_on_holidays' => ['sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): DoctorScheduleData
    {
        return DoctorScheduleData::fromRequest($this);
    }
}
