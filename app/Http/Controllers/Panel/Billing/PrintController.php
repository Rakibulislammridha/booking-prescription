<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Billing;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Services\PrintDocument;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Invoice and money-receipt printing (BRIEF §5.I). Both documents are rendered from the STORED rows — the
 * frozen invoice totals and the payment row — never from a live re-computation, so reprinting a six-month-old
 * receipt yields exactly the paper the patient was handed.
 *
 * `?paper=a4|80|58` selects A4 or an 80/58 mm thermal roll; the default follows the branch's token-slip width.
 */
final class PrintController extends Controller
{
    public function __construct(private readonly PrintDocument $documents, private readonly AuditRecorder $audit) {}

    public function invoice(Request $request, Invoice $invoice): Response
    {
        $this->authorize('print', $invoice);
        $paper = PrintDocument::paper($request->query('paper'), $invoice->branch);

        $this->audit->record(AuditAction::Print, $invoice, null, null, ['document' => 'invoice', 'paper' => $paper]);

        return $this->html(view('print.invoice', $this->documents->invoice($invoice, $paper))->render());
    }

    public function receipt(Request $request, Invoice $invoice, Payment $payment): Response
    {
        $this->authorize('print', $invoice);
        abort_unless($payment->invoice_id === $invoice->id, 404);

        $paper = PrintDocument::paper($request->query('paper'), $invoice->branch, 'branch');

        $this->audit->record(AuditAction::Print, $payment, null, null, ['document' => 'receipt', 'paper' => $paper, 'invoice' => $invoice->number]);

        return $this->html(view('print.receipt', $this->documents->receipt($invoice, $payment, $paper))->render());
    }

    /** Same headers as the prescription print path: never cached, never indexed. */
    private function html(string $html): Response
    {
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
