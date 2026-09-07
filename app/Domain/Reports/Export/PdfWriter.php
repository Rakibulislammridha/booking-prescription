<?php

declare(strict_types=1);

namespace App\Domain\Reports\Export;

use App\Domain\Prescription\Render\PdfRenderer;
use App\Domain\Prescription\Render\PrintFonts;
use App\Domain\Reports\Data\ReportTable;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Spatie\Browsershot\Browsershot;

/**
 * PDF through the SAME Browsershot path the rest of the app uses (BRIEF §2 bans DomPDF outright: it cannot
 * shape Bangla conjuncts, so `ডা. নাসরিন সুলতানা` comes out as broken clusters — on a prescription that is a
 * safety problem, on a report it is simply a document the clinic cannot read).
 *
 * Font handling copies `App\Domain\Prescription\Render\PdfRenderer` exactly, and reuses its `PrintFonts`:
 * Browsershot renders an HTML STRING with no base URL, so a linked `/fonts/*.woff2` would resolve to nothing
 * and Chrome would silently fall back. `PrintFonts::css(inline: true)` inlines the woff2 as data URIs, and
 * `waitForFunction('document.fonts.status === "loaded"')` makes layout wait for the decode so the PDF's line
 * breaks match the preview.
 *
 * A PDF is a paginated document, not a data dump: past `MAX_ROWS` it is truncated with a printed notice, and
 * the footer tells the reader to take the CSV or Excel file instead. Silently cutting a report would be worse
 * than refusing one.
 */
final class PdfWriter
{
    public const MAX_ROWS = 2000;

    public function __construct(private readonly ViewFactory $views, private readonly PrintFonts $fonts) {}

    public function bytes(ReportTable $table): string
    {
        return $this->browsershot($this->html($table))->pdf();
    }

    public function toFile(ReportTable $table, string $path): void
    {
        $this->browsershot($this->html($table))->savePdf($path);
    }

    /** The same HTML the PDF is made of — served directly when Chrome is unavailable, so the export never 500s. */
    public function html(ReportTable $table): string
    {
        $rows = [];
        $truncated = false;

        foreach ($table->rows() as $row) {
            if (count($rows) >= self::MAX_ROWS) {
                $truncated = true;

                break;
            }

            $rows[] = $row;
        }

        return $this->views->make('print.reports.table', [
            'table' => $table,
            'rows' => $rows,
            'truncated' => $truncated,
            'maxRows' => self::MAX_ROWS,
            'fontCss' => $this->fonts->css(inline: true),
            'clinic' => Tenancy::check() ? Tenancy::current()->name : '',
            'printedAt' => Clock::now()->format('d M Y, h:i a'),
        ])->render();
    }

    public function available(): bool
    {
        $path = PdfRenderer::chromePath();

        return $path !== '' && is_file($path);
    }

    private function browsershot(string $html): Browsershot
    {
        $shot = Browsershot::html($html)
            ->setChromePath(PdfRenderer::chromePath())
            ->noSandbox()
            ->addChromiumArguments([
                'disable-gpu',
                'disable-dev-shm-usage',
                'no-zygote',
                'disable-extensions',
                'font-render-hinting' => 'none',   // hinting mangles Bangla conjunct spacing at print DPI
            ])
            ->format('A4')
            ->landscape()                          // a table with eight columns is a landscape document
            ->margins(10, 10, 12, 10, 'mm')
            ->showBackground()
            ->emulateMedia('print')
            ->waitForFunction('document.fonts.status === "loaded"', timeout: 5000)
            ->timeout((int) config('prescription.pdf.timeout', 60))
            ->protocolTimeout((int) config('prescription.pdf.protocol_timeout', 60000));

        $node = (string) config('services.node.binary', '');
        $npm = (string) config('services.node.npm', '');

        if ($node !== '' && $node !== 'node') {
            $shot->setNodeBinary($node);
        }

        if ($npm !== '' && $npm !== 'npm') {
            $shot->setNpmBinary($npm);
        }

        return $shot;
    }
}
