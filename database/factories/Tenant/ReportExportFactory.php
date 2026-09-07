<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Enums\ExportStatus;
use App\Domain\Reports\Enums\ReportKind;
use App\Models\Tenant\ReportExport;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportExport>
 */
final class ReportExportFactory extends Factory
{
    protected $model = ReportExport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => fn () => User::query()->value('id') ?? User::factory(),
            'report' => ReportKind::Appointments->value,
            'format' => ExportFormat::Csv,
            'status' => ExportStatus::Pending,
            'filters' => ['from' => '2026-03-01', 'to' => '2026-03-31'],
            'title' => 'Appointments',
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => ExportStatus::Ready,
            'file_path' => 'tenants/9001/reports/example.csv',
            'file_size' => 1024,
            'row_count' => 10,
            'completed_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => ['status' => ExportStatus::Failed, 'error' => 'boom']);
    }
}
