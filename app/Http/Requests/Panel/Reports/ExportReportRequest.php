<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Reports;

use App\Domain\Reports\Enums\ExportFormat;
use App\Http\Requests\Panel\Reports\Concerns\ResolvesReportFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Downloading a report: the same filters, one more parameter, and a stricter gate — reading a number on screen
 * and walking out with the file are different permissions (BRIEF §5.N).
 */
final class ExportReportRequest extends FormRequest
{
    use ResolvesReportFilters;

    public function authorize(): bool
    {
        return Gate::allows('reports.download', $this->kind());
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return $this->filterRules() + [
            'format' => ['nullable', 'string', 'in:'.implode(',', ExportFormat::values())],
        ];
    }

    /** Named `exportFormat` because `Illuminate\Http\Request::format()` already means content negotiation. */
    public function exportFormat(): ExportFormat
    {
        $value = $this->query('format');

        return ExportFormat::tryFrom(is_string($value) ? $value : '') ?? ExportFormat::Csv;
    }
}
