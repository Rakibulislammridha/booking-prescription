<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Domain\Prescription\Data\PrescriptionIndexFilters;
use App\Domain\Prescription\Enums\PrescriptionStatus;
use App\Models\Tenant\Prescription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /panel/prescriptions ?q=&doctor=&status=&from=&to=&page= — the index's filter bar (PrescriptionPolicy::viewAny). */
final class IndexPrescriptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('viewAny', Prescription::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:60'],
            'doctor' => ['nullable', 'string', 'alpha_num:ascii', 'size:26'],
            'status' => ['nullable', Rule::enum(PrescriptionStatus::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function toData(): PrescriptionIndexFilters
    {
        $string = fn (string $key): ?string => is_string($v = $this->validated($key)) && trim($v) !== '' ? trim($v) : null;
        $status = $string('status');

        return new PrescriptionIndexFilters(
            q: $string('q') ?? '',
            doctor: $string('doctor'),
            status: $status === null ? null : PrescriptionStatus::from($status),
            from: $string('from'),
            to: $string('to'),
            page: max(1, (int) ($this->validated('page') ?? 1)),
        );
    }
}
