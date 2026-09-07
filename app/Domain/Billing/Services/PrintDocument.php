<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Shared\Money;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Payment;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;

/**
 * Builds the view bag for `print/invoice` and `print/receipt` from the STORED rows only.
 *
 * Nothing here recomputes money: the invoice's frozen totals and the payment's own amount are formatted and
 * handed to the template. Reprinting an old bill therefore cannot change it, even if the VAT rate, the fee or a
 * commission rule has moved on since.
 *
 * Labels are bilingual pairs rather than `__()` lookups, because a printed bill's language is a property of the
 * document (the clinic hands it to a Bangla-reading patient) and not of the logged-in staff member's UI locale —
 * the same reasoning the prescription pipeline uses for `PrintLabels`.
 */
final class PrintDocument
{
    public const PAPERS = ['a4', '80', '58'];

    /** @var array<string, array{en: string, bn: string}> */
    private const LABELS = [
        'invoice' => ['en' => 'Invoice', 'bn' => 'চালান'],
        'money_receipt' => ['en' => 'Money Receipt', 'bn' => 'টাকা প্রাপ্তির রসিদ'],
        'invoice_no' => ['en' => 'Invoice No', 'bn' => 'চালান নং'],
        'receipt_no' => ['en' => 'Receipt No', 'bn' => 'রসিদ নং'],
        'date' => ['en' => 'Date', 'bn' => 'তারিখ'],
        'patient' => ['en' => 'Patient', 'bn' => 'রোগী'],
        'patient_id' => ['en' => 'Patient ID', 'bn' => 'রোগী আইডি'],
        'mobile' => ['en' => 'Mobile', 'bn' => 'মোবাইল'],
        'doctor' => ['en' => 'Doctor', 'bn' => 'ডাক্তার'],
        'description' => ['en' => 'Description', 'bn' => 'বিবরণ'],
        'qty' => ['en' => 'Qty', 'bn' => 'পরিমাণ'],
        'rate' => ['en' => 'Rate', 'bn' => 'দর'],
        'amount' => ['en' => 'Amount', 'bn' => 'টাকা'],
        'subtotal' => ['en' => 'Subtotal', 'bn' => 'উপমোট'],
        'discount' => ['en' => 'Discount', 'bn' => 'ছাড়'],
        'coupon' => ['en' => 'Coupon', 'bn' => 'কুপন'],
        'vat' => ['en' => 'VAT', 'bn' => 'ভ্যাট'],
        'total' => ['en' => 'Total', 'bn' => 'সর্বমোট'],
        'paid' => ['en' => 'Paid', 'bn' => 'পরিশোধিত'],
        'due' => ['en' => 'Due', 'bn' => 'বকেয়া'],
        'received' => ['en' => 'Received', 'bn' => 'গৃহীত'],
        'method' => ['en' => 'Method', 'bn' => 'মাধ্যম'],
        'received_by' => ['en' => 'Received by', 'bn' => 'গ্রহণকারী'],
        'in_words' => ['en' => 'In words', 'bn' => 'কথায়'],
        'signature' => ['en' => 'Authorised signature', 'bn' => 'অনুমোদিত স্বাক্ষর'],
        'thank_you' => ['en' => 'Thank you', 'bn' => 'ধন্যবাদ'],
        'free_followup' => ['en' => 'Free follow-up', 'bn' => 'বিনামূল্যে ফলো-আপ'],
        'paid_stamp' => ['en' => 'PAID', 'bn' => 'পরিশোধিত'],
        'void_stamp' => ['en' => 'VOID', 'bn' => 'বাতিল'],
        'method_cash' => ['en' => 'Cash', 'bn' => 'নগদ'],
        'method_card' => ['en' => 'Card', 'bn' => 'কার্ড'],
        'method_bkash' => ['en' => 'bKash', 'bn' => 'বিকাশ'],
        'method_nagad' => ['en' => 'Nagad', 'bn' => 'নগদ'],
        'method_sslcommerz' => ['en' => 'Card / Online', 'bn' => 'কার্ড / অনলাইন'],
        'method_other' => ['en' => 'Other', 'bn' => 'অন্যান্য'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function invoice(Invoice $invoice, string $paper): array
    {
        $invoice->loadMissing(['patient', 'doctor', 'branch', 'items', 'payments', 'discounts', 'appointment']);

        return $this->common($invoice, $paper) + [
            'items' => $invoice->items->map(fn ($item): array => [
                'description' => $item->description,
                'type' => $item->type->value,
                'quantity' => $item->quantity,
                'unit_price' => Money::bdt($item->unit_price_paisa)->format(),
                'line_total' => Money::bdt($item->line_total_paisa)->format(),
                'is_free' => $item->line_total_paisa === 0,
            ])->all(),
            'payments' => $invoice->payments->filter(fn (Payment $p) => $p->status->isSettled())->map(fn (Payment $p): array => [
                'receipt_number' => $p->receipt_number,
                'method' => $this->methodLabel($p),
                'amount' => Money::bdt($p->amount_paisa)->format(),
                'paid_at' => $p->paid_at?->setTimezone(Clock::timezone())->format('d M Y, h:i a'),
            ])->values()->all(),
            'discounts' => $invoice->discounts->map(fn ($d): array => [
                'reason' => $d->reason_code->value,
                'note' => $d->note,
                'amount' => Money::bdt($d->amount_paisa)->format(),
            ])->all(),
            // The fee rule sentence FeeResolver wrote at booking ("Free follow-up: day 7 of 15") is what makes a
            // ৳0 consultation line self-explanatory on the printed bill.
            'fee_note' => $invoice->appointment_id === null ? $invoice->notes : ($invoice->appointment->fee_rule_reason ?? $invoice->notes),
        ];
    }

    /** @return array<string, mixed> */
    public function receipt(Invoice $invoice, Payment $payment, string $paper): array
    {
        $invoice->loadMissing(['patient', 'doctor', 'branch']);
        $payment->loadMissing('receivedBy');

        return $this->common($invoice, $paper) + [
            'payment' => [
                'receipt_number' => $payment->receipt_number,
                'method' => $this->methodLabel($payment),
                'amount' => Money::bdt($payment->amount_paisa)->format(),
                'amount_paisa' => $payment->amount_paisa,
                'in_words' => self::inWords($payment->amount_paisa),
                'paid_at' => $payment->paid_at?->setTimezone(Clock::timezone())->format('d M Y, h:i a'),
                'received_by' => $payment->receivedBy?->name,
                'refunded' => $payment->refunded_paisa > 0 ? Money::bdt($payment->refunded_paisa)->format() : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function common(Invoice $invoice, string $paper): array
    {
        $tenant = Tenancy::current();
        $branch = $invoice->branch;

        return [
            'paper' => $paper,
            'thermal' => $paper !== 'a4',
            'widthMm' => match ($paper) {
                '58' => 58,
                '80' => 80,
                default => 210,
            },
            'labels' => self::LABELS,
            'clinic' => [
                'name' => $tenant->name,
                'branch' => $branch->name ?? null,
                'address' => $branch->address ?? null,
                'phone' => $branch->phone ?? null,
            ],
            'invoice' => [
                'number' => $invoice->number,
                'status' => $invoice->status->value,
                'issued_at' => ($invoice->issued_at ?? $invoice->created_at)?->setTimezone(Clock::timezone())->format('d M Y, h:i a'),
                'subtotal' => Money::bdt($invoice->subtotal_paisa)->format(),
                'discount' => Money::bdt($invoice->discount_paisa)->format(),
                'coupon_discount' => Money::bdt($invoice->coupon_discount_paisa)->format(),
                'vat' => Money::bdt($invoice->vat_paisa)->format(),
                'total' => Money::bdt($invoice->total_paisa)->format(),
                'paid' => Money::bdt($invoice->paid_paisa)->format(),
                'due' => Money::bdt($invoice->due_paisa)->format(),
                'has_discount' => $invoice->discount_paisa > 0,
                'has_coupon' => $invoice->coupon_discount_paisa > 0,
                'has_vat' => $invoice->vat_paisa > 0,
                'has_due' => $invoice->due_paisa > 0,
                'is_void' => $invoice->status->value === 'void',
                'in_words' => self::inWords($invoice->total_paisa),
            ],
            'patient' => [
                'name' => $invoice->patient->name,
                'code' => $invoice->patient->patient_code,
                'mobile' => $invoice->patient->mobile,
            ],
            'doctor' => $invoice->doctor_id === null ? null : ['name' => $invoice->doctor->name, 'name_bn' => $invoice->doctor->name_bn],
        ];
    }

    private function methodLabel(Payment $payment): string
    {
        $label = self::LABELS['method_'.$payment->method->value];

        return $label['en'].' / '.$label['bn'];
    }

    /**
     * `?paper=` always wins. Otherwise an INVOICE defaults to A4 — it is the formal bill a patient files or
     * claims on — while a RECEIPT follows the branch's token-slip width, so it comes straight off the thermal
     * printer already at the desk.
     */
    public static function paper(mixed $requested, ?Branch $branch, string $fallback = 'a4'): string
    {
        $value = is_string($requested) ? mb_strtolower(trim($requested)) : '';

        if (in_array($value, self::PAPERS, true)) {
            return $value;
        }

        if ($fallback !== 'branch') {
            return in_array($fallback, self::PAPERS, true) ? $fallback : 'a4';
        }

        $width = $branch === null ? null : ($branch->settings['token_slip_width_mm'] ?? null);

        return match (true) {
            (int) $width === 80 => '80',
            (int) $width === 58 => '58',
            default => 'a4',
        };
    }

    /** BDT amount in English words for the receipt line ("Taka Five Hundred only"). */
    public static function inWords(int $paisa): string
    {
        $taka = intdiv(abs($paisa), 100);
        $poisha = abs($paisa) % 100;
        $words = self::number($taka);
        $text = 'Taka '.$words;

        if ($poisha > 0) {
            $text .= ' and '.self::number($poisha).' Poisha';
        }

        return $text.' only';
    }

    /** Lakh/crore grouping, the way a Bangladeshi receipt reads. */
    private static function number(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }

        $units = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        $below100 = function (int $v) use ($units, $tens): string {
            if ($v < 20) {
                return $units[$v];
            }

            return trim($tens[intdiv($v, 10)].' '.$units[$v % 10]);
        };

        $below1000 = function (int $v) use ($below100, $units): string {
            $out = $v >= 100 ? $units[intdiv($v, 100)].' Hundred' : '';

            return trim($out.' '.$below100($v % 100));
        };

        $parts = [];

        foreach ([10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand'] as $divisor => $name) {
            if ($n >= $divisor) {
                $parts[] = $below1000(intdiv($n, $divisor)).' '.$name;
                $n %= $divisor;
            }
        }

        if ($n > 0) {
            $parts[] = $below1000($n);
        }

        return trim(implode(' ', $parts));
    }
}
