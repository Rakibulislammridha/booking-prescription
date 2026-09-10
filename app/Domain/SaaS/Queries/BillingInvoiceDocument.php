<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\Billing\Services\PrintDocument;
use App\Domain\Prescription\Render\PrintFonts;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\SubscriptionPaymentStatus;
use App\Domain\Shared\Money;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\SubscriptionPayment;
use App\Support\Clock;
use Carbon\CarbonImmutable;

/**
 * The view bag for `print/subscription-invoice` — the platform's bill to a clinic, built from the STORED row
 * only (frozen totals, frozen `line_items`), the same rule the tenant-side `PrintDocument` follows: a reprint six
 * months later is the paper the customer already has.
 *
 * Labels are bilingual pairs rather than `__()` lookups because the language of a printed bill is a property of
 * the document (the clinic's owner reads Bangla) and not of the operator's console locale.
 */
final class BillingInvoiceDocument
{
    /** @var array<string, array{en: string, bn: string}> */
    private const LABELS = [
        'title' => ['en' => 'Subscription Invoice', 'bn' => 'সাবস্ক্রিপশন চালান'],
        'invoice_no' => ['en' => 'Invoice No', 'bn' => 'চালান নং'],
        'issued' => ['en' => 'Issued', 'bn' => 'ইস্যুর তারিখ'],
        'due' => ['en' => 'Payment due', 'bn' => 'পরিশোধের শেষ তারিখ'],
        'period' => ['en' => 'Billing period', 'bn' => 'বিলিং সময়কাল'],
        'billed_to' => ['en' => 'Billed to', 'bn' => 'গ্রাহক'],
        'owner' => ['en' => 'Owner', 'bn' => 'মালিক'],
        'email' => ['en' => 'Email', 'bn' => 'ইমেইল'],
        'mobile' => ['en' => 'Mobile', 'bn' => 'মোবাইল'],
        'description' => ['en' => 'Description', 'bn' => 'বিবরণ'],
        'qty' => ['en' => 'Qty', 'bn' => 'পরিমাণ'],
        'rate' => ['en' => 'Unit price', 'bn' => 'একক মূল্য'],
        'amount' => ['en' => 'Amount', 'bn' => 'টাকা'],
        'subtotal' => ['en' => 'Subtotal', 'bn' => 'উপমোট'],
        'discount' => ['en' => 'Discount', 'bn' => 'ছাড়'],
        'vat' => ['en' => 'VAT', 'bn' => 'ভ্যাট'],
        'total' => ['en' => 'Total', 'bn' => 'সর্বমোট'],
        'paid' => ['en' => 'Paid', 'bn' => 'পরিশোধিত'],
        'balance' => ['en' => 'Balance due', 'bn' => 'বকেয়া'],
        'in_words' => ['en' => 'In words', 'bn' => 'কথায়'],
        'payments' => ['en' => 'Payments received', 'bn' => 'গৃহীত পেমেন্ট'],
        'date' => ['en' => 'Date', 'bn' => 'তারিখ'],
        'method' => ['en' => 'Method', 'bn' => 'মাধ্যম'],
        'reference' => ['en' => 'Reference', 'bn' => 'রেফারেন্স'],
        'paid_stamp' => ['en' => 'PAID', 'bn' => 'পরিশোধিত'],
        'void_stamp' => ['en' => 'VOID', 'bn' => 'বাতিল'],
        'overdue_stamp' => ['en' => 'OVERDUE', 'bn' => 'মেয়াদোত্তীর্ণ'],
        'how_to_pay' => ['en' => 'Pay by bank transfer or bKash to the account on your contract and quote the invoice number; the invoice is marked paid the same day.', 'bn' => 'চুক্তিতে দেওয়া অ্যাকাউন্টে ব্যাংক ট্রান্সফার বা বিকাশে পরিশোধ করুন এবং চালান নম্বর উল্লেখ করুন; একই দিনে চালানটি পরিশোধিত হিসেবে চিহ্নিত হবে।'],
        'thank_you' => ['en' => 'Thank you for your business', 'bn' => 'আপনাকে ধন্যবাদ'],
        'signature' => ['en' => 'Authorised signature', 'bn' => 'অনুমোদিত স্বাক্ষর'],
        'printed' => ['en' => 'Printed', 'bn' => 'মুদ্রণ'],
        'method_bkash' => ['en' => 'bKash', 'bn' => 'বিকাশ'],
        'method_nagad' => ['en' => 'Nagad', 'bn' => 'নগদ'],
        'method_sslcommerz' => ['en' => 'Card / Online', 'bn' => 'কার্ড / অনলাইন'],
        'method_bank_transfer' => ['en' => 'Bank transfer', 'bn' => 'ব্যাংক ট্রান্সফার'],
        'method_cash' => ['en' => 'Cash', 'bn' => 'নগদ টাকা'],
        'method_manual' => ['en' => 'Recorded by hand', 'bn' => 'হাতে লেখা'],
    ];

    public function __construct(private readonly PrintFonts $fonts) {}

    /**
     * @param  bool  $inlineFonts  true for the PDF (Browsershot renders a string with no base URL)
     * @return array<string, mixed>
     */
    public function build(SubscriptionInvoice $invoice, bool $inlineFonts = false): array
    {
        $invoice->loadMissing(['tenant', 'subscription.plan', 'payments']);
        $tenant = $invoice->tenant;
        $paid = (int) $invoice->getAttribute('paid_paisa');
        $balance = max(0, $invoice->total_paisa - $paid);
        $timezone = $tenant->timezone !== '' ? $tenant->timezone : Clock::DEFAULT_TIMEZONE;
        $date = fn (?CarbonImmutable $at): ?string => $at?->setTimezone($timezone)->format('d M Y');

        /** @var array<int, array<string, mixed>> $lines */
        $lines = $invoice->line_items;

        return [
            'labels' => self::LABELS,
            'fontCss' => $this->fonts->css(inline: $inlineFonts),
            'platform' => [
                'name' => (string) config('app.name'),
                'domain' => (string) config('tenancy.central_domain'),
            ],
            'invoice' => [
                'number' => $invoice->number,
                'status' => $invoice->status->value,
                'issued_at' => $date($invoice->getAttribute('issued_at')) ?? $date($invoice->getAttribute('created_at')),
                'due_at' => $date($invoice->getAttribute('due_at')),
                'period_start' => $invoice->getAttribute('period_start')?->format('d M Y'),
                'period_end' => $invoice->getAttribute('period_end')?->format('d M Y'),
                'subtotal' => Money::bdt((int) $invoice->getAttribute('subtotal_paisa'))->format(),
                'discount' => Money::bdt((int) $invoice->getAttribute('discount_paisa'))->format(),
                'vat' => Money::bdt((int) $invoice->getAttribute('tax_paisa'))->format(),
                'total' => Money::bdt($invoice->total_paisa)->format(),
                'paid' => Money::bdt($paid)->format(),
                'balance' => Money::bdt($balance)->format(),
                'has_discount' => (int) $invoice->getAttribute('discount_paisa') > 0,
                'has_vat' => (int) $invoice->getAttribute('tax_paisa') > 0,
                'has_balance' => $balance > 0 && $invoice->status !== SubscriptionInvoiceStatus::Void,
                'is_paid' => $invoice->status === SubscriptionInvoiceStatus::Paid,
                'is_void' => $invoice->status === SubscriptionInvoiceStatus::Void,
                'is_overdue' => $invoice->status === SubscriptionInvoiceStatus::Overdue,
                'in_words' => PrintDocument::inWords($invoice->total_paisa),
                'plan' => $invoice->subscription?->plan->name,
                'cycle' => $invoice->subscription?->billing_cycle->value,
            ],
            'customer' => [
                'name' => $tenant->name,
                'host' => $tenant->primaryHost(),
                'owner' => $tenant->owner_name,
                'email' => $tenant->owner_email,
                'mobile' => $tenant->owner_mobile,
            ],
            'items' => array_map(fn (array $line): array => [
                'description' => (string) ($line['description'] ?? ''),
                'quantity' => (int) ($line['quantity'] ?? 1),
                'unit' => Money::bdt((int) ($line['unit_paisa'] ?? 0))->format(),
                'total' => Money::bdt((int) ($line['total_paisa'] ?? 0))->format(),
            ], $lines),
            'payments' => $invoice->payments
                ->filter(fn (SubscriptionPayment $p) => $p->status === SubscriptionPaymentStatus::Succeeded)
                ->sortBy('id')
                ->map(fn (SubscriptionPayment $p): array => [
                    'paid_at' => $date($p->getAttribute('paid_at')) ?? '—',
                    'method' => self::LABELS['method_'.$p->method->value]['en'].' / '.self::LABELS['method_'.$p->method->value]['bn'],
                    'reference' => (string) ($p->getAttribute('gateway_txn_id') ?? '—'),
                    'amount' => Money::bdt($p->amount_paisa)->format(),
                ])->values()->all(),
            'printed_at' => Clock::now()->format('d M Y, h:i a'),
        ];
    }
}
