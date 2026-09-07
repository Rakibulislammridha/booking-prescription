<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Enums\ExportStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\ReportExportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A queued report export (BRIEF §5.L). Small reports never create one — they stream straight down the
 * response — so a row here means "this was big enough for the `reports` queue", and it is what the owner comes
 * back to when the file is ready.
 *
 * It stores the FILTERS, never the numbers: the file is authored once by the job, and re-reading the row is a
 * download, not a recomputation, so the bytes can never drift from the audit entry that recorded who asked.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property string $report
 * @property ExportFormat $format
 * @property ExportStatus $status
 * @property array<string, mixed> $filters
 * @property string $title
 * @property int|null $row_count
 * @property string|null $file_path
 * @property int|null $file_size
 * @property string|null $error
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property-read User $user
 */
final class ReportExport extends TenantModel
{
    /** @use HasFactory<ReportExportFactory> */
    use HasFactory;

    protected static string $factory = ReportExportFactory::class;

    protected static bool $publicId = true;

    protected $table = 'report_exports';

    protected $fillable = [
        'user_id', 'report', 'format', 'status', 'filters', 'title', 'row_count', 'file_path', 'file_size',
        'error', 'completed_at', 'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'format' => ExportFormat::class,
            'status' => ExportStatus::class,
            'filters' => 'array',
            'row_count' => 'integer',
            'file_size' => 'integer',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<ReportExport>  $query */
    public function scopeReady(Builder $query): void
    {
        $query->where('status', ExportStatus::Ready->value);
    }

    public function isDownloadable(): bool
    {
        return $this->status === ExportStatus::Ready
            && $this->file_path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function filename(): string
    {
        return sprintf('%s-%s.%s', $this->report, $this->created_at?->format('Ymd-His') ?? 'export', $this->format->extension());
    }
}
