<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Audit;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Models\Central\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The audit log's filter bar, shared by the screen and the CSV export so both describe the same rows. */
final class AuditSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') instanceof SuperAdmin;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['nullable', 'string', Rule::in(CentralAuditAction::values())],
            'tenant' => ['nullable', 'string', 'max:26'],
            'admin' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{tenant: string|null, admin: int|null, action: string|null, from: string|null, to: string|null, q: string|null} */
    public function filters(): array
    {
        $admin = $this->input('admin');

        return [
            'tenant' => $this->filled('tenant') ? (string) $this->string('tenant') : null,
            'admin' => is_numeric($admin) ? (int) $admin : null,
            'action' => $this->filled('action') ? (string) $this->string('action') : null,
            'from' => $this->filled('from') ? (string) $this->string('from') : null,
            'to' => $this->filled('to') ? (string) $this->string('to') : null,
            'q' => $this->filled('q') ? trim((string) $this->string('q')) : null,
        ];
    }

    /** The filters as the screen echoes them back — strings, never nulls, so the controls stay controlled. */
    /** @return array<string, string> */
    public function echo(): array
    {
        $filters = $this->filters();

        return [
            'tenant' => $filters['tenant'] ?? '',
            'admin' => $filters['admin'] === null ? '' : (string) $filters['admin'],
            'action' => $filters['action'] ?? '',
            'from' => $filters['from'] ?? '',
            'to' => $filters['to'] ?? '',
            'q' => $filters['q'] ?? '',
        ];
    }
}
