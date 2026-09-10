<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Usage;

use App\Domain\SaaS\Enums\UsageMetric;
use App\Models\Central\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The usage board's query string: metric, the over/near filter, the sort and the page. */
final class UsageBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') instanceof SuperAdmin;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'metric' => ['nullable', Rule::in(UsageMetric::values())],
            'filter' => ['nullable', Rule::in(['all', 'over', 'near'])],
            'sort' => ['nullable', Rule::in(['percent', 'value', 'name'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function metric(): UsageMetric
    {
        return UsageMetric::tryFrom((string) $this->input('metric', '')) ?? UsageMetric::Appointments;
    }

    /** @return 'all'|'over'|'near' */
    public function filter(): string
    {
        $filter = (string) $this->input('filter', 'all');

        return $filter === 'over' || $filter === 'near' ? $filter : 'all';
    }

    /** @return 'percent'|'value'|'name' */
    public function sort(): string
    {
        $sort = (string) $this->input('sort', 'percent');

        return $sort === 'value' || $sort === 'name' ? $sort : 'percent';
    }

    public function page(): int
    {
        return max(1, (int) $this->input('page', 1));
    }
}
