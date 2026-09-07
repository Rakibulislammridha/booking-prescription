<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

enum ExportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Pdf = 'pdf';

    public function extension(): string
    {
        return $this->value;
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Pdf => 'application/pdf',
        };
    }

    /** CSV and XLSX stream row by row; a PDF has to lay out pages, so it is capped (PdfWriter::MAX_ROWS). */
    public function streams(): bool
    {
        return $this !== self::Pdf;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
