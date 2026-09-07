<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Render;

use App\Domain\Prescription\Data\PrescriptionSnapshot;
use Spatie\Browsershot\Browsershot;
use Spatie\Browsershot\Exceptions\CouldNotTakeBrowsershot;

/**
 * PRESCRIPTION.md §7.5 — HTML → PDF through headless Chrome. DomPDF is banned (BRIEF §2): it cannot shape Bangla
 * conjuncts, so `ফার্মেসি` would come out as broken clusters or boxes on a legal document. Only a real browser
 * engine gets the typography right, and the same engine that renders the doctor's print preview renders the PDF.
 *
 * Paper and margins come from the SAME PadGeometry the CSS uses, because Puppeteer's page box wins over `@page`:
 * if the two disagreed, the preprinted band would be off by exactly the difference.
 */
final class PdfRenderer
{
    public function __construct(private readonly PrescriptionRenderer $renderer) {}

    /** @throws CouldNotTakeBrowsershot */
    public function pdf(PrescriptionSnapshot $snapshot, RenderOptions $options): string
    {
        // A PDF is always rendered with fonts inlined: Browsershot gets an HTML string with no base URL, so a
        // linked /fonts/*.woff2 would silently not load and Bangla would fall back to whatever Chrome finds.
        return $this->browsershot($this->renderer->render($snapshot, $options->withPurpose('pdf')), new PadGeometry($snapshot->pad(), $options), $options)->pdf();
    }

    public function html(PrescriptionSnapshot $snapshot, RenderOptions $options): string
    {
        return $this->renderer->render($snapshot, $options->withPurpose('pdf'));
    }

    /** Chrome must exist before a job burns three attempts discovering it doesn't; also gates the `browsershot` test group. */
    public function available(): bool
    {
        $path = self::chromePath();

        return $path !== '' && is_file($path);
    }

    public static function chromePath(): string
    {
        $configured = (string) config('prescription.pdf.chrome_path', '');

        return $configured !== '' ? $configured : (string) config('services.chrome.path', '/usr/bin/google-chrome');
    }

    private function browsershot(string $html, PadGeometry $pad, RenderOptions $options): Browsershot
    {
        $margins = $pad->margins();
        $shot = Browsershot::html($html)
            ->setChromePath(self::chromePath())
            // No Docker, no sudo on the target hosts and Chrome's sandbox needs user namespaces: --no-sandbox is
            // safe here only because the HTML we feed it is our own template over our own frozen snapshot.
            ->noSandbox()
            ->addChromiumArguments([
                'disable-gpu',
                'disable-dev-shm-usage',
                'no-zygote',
                'disable-extensions',
                'font-render-hinting' => 'none',   // hinting mangles Bangla conjunct spacing at print DPI
            ])
            ->format($options->paper)
            ->margins((float) $margins['top'], (float) $margins['right'], (float) $margins['bottom'], (float) $margins['left'], 'mm')
            ->showBackground()
            ->emulateMedia('print')
            // Fonts are inlined, so "loaded" is a local decode, not a fetch — but layout must wait for it or the
            // Bangla line breaks in the PDF differ from the preview.
            ->waitForFunction('document.fonts.status === "loaded"', timeout: 5000)
            ->timeout((int) config('prescription.pdf.timeout', 60))
            ->protocolTimeout((int) config('prescription.pdf.protocol_timeout', 60000));

        if ($options->orientation === 'landscape') {
            $shot->landscape();
        }

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
