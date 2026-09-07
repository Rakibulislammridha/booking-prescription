<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Domain\Clinic\Data\DoctorLeaveData;
use App\Domain\Clinic\Enums\LeaveType;
use App\Models\Tenant\DoctorLeave;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDoctorLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $doctorId = $this->integer('doctor_id') ?: null;

        return $this->user('web')?->can('create', [DoctorLeave::class, $doctorId]) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'doctor_id' => ['required', 'integer', Rule::exists('doctors', 'id')->whereNull('deleted_at')],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'type' => ['sometimes', Rule::enum(LeaveType::class)],
            'reason' => ['nullable', 'string', 'max:255'],
            'notify_patients' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): DoctorLeaveData
    {
        return DoctorLeaveData::fromRequest($this);
    }
}
