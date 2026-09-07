<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Reports;

use App\Http\Requests\Panel\Reports\Concerns\ResolvesReportFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Reading a report page: the `reports.open` gate decides whether this user may see this family at all (money
 * needs `reports.financial.view`, clinical detail needs `reports.clinical.view`), and the trait turns the query
 * string into `ReportFilters`.
 */
final class ReportFilterRequest extends FormRequest
{
    use ResolvesReportFilters;

    public function authorize(): bool
    {
        return Gate::allows('reports.open', $this->kind());
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return $this->filterRules();
    }
}
