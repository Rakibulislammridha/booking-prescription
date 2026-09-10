<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\Prescription\Render\PdfRenderer;
use App\Domain\SaaS\Queries\BillingInvoiceDocument;
use App\Models\Central\SubscriptionInvoice;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Spatie\Browsershot\Browsershot;

/**
 * A platform invoice as HTML (the print view) or PDF, through the SAME Browsershot path every other document
 * in the product uses (ARCHITECTURE §8.5; `Reports\Export\PdfWriter` is the template this copies). DomPDF is
 * banned: the customer's name on a subscription invoice is Bangla, and only a browser engine shapes it.
 *
 * `available()` gates the PDF: when Chrome is not on the host the controller serves the HTML instead of 500ing,
 * because an invoice an operator cannot print is worse than one they must print from the browser.
 */
final class SubscriptionInvoicePdf
{
    public function __construct(
        private readonly ViewFactory $views,
        private readonly BillingInvoiceDocument $document,
    ) {}

    public function html(SubscriptionInvoice $invoice, bool $inlineFonts = false): string
    {
        return $this->views->make('print.subscription-invoice', $this->document->build($invoice, $inlineFonts))->render();
    }

    public function pdf(SubscriptionInvoice $invoice): string
    {
        return $this->browsershot($this->html($invoice, inlineFonts: true))->pdf();
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
                'font-render-hinting' => 'none',
            ])
            ->format('A4')
            ->margins(14, 12, 14, 12, 'mm')
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
