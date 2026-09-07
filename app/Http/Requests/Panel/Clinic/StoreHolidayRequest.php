<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Domain\Clinic\Data\HolidayData;
use App\Models\Tenant\Holiday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', Holiday::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'holiday_date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:160'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
        ];
    }

    public function toData(): HolidayData
    {
        return HolidayData::fromRequest($this);
    }
}
