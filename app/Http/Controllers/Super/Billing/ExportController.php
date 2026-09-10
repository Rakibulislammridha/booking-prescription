<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Billing;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Queries\BillingInvoices;
use App\Domain\SaaS\Queries\BillingPayments;
use App\Domain\SaaS\Queries\BillingSubscriptions;
use App\Domain\SaaS\Services\CentralAudit;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `GET billing/export/{kind}?…filters` — subscriptions, invoices or payments as CSV, STREAMED (a platform's
 * whole ledger must not sit in PHP memory) and AUDITED (who exported what, with which filters — an export is
 * the one console read that leaves the building).
 *
 * Same file shape as the tenant-side `CsvWriter`: UTF-8 BOM so Excel opens Bangla names, money as integer paisa
 * AND as a taka string, so the sheet can be summed without anyone dividing by a hundred.
 */
final class ExportController extends Controller
{
    /** @var array<int, string> */
    public const KINDS = ['subscriptions', 'invoices', 'payments'];

    public function __invoke(Request $request, string $kind, BillingSubscriptions $subscriptions, BillingInvoices $invoices, BillingPayments $payments, CentralAudit $audit): StreamedResponse
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);

        /** @var array<string, string> $filters */
        $filters = array_map(fn ($v): string => is_string($v) ? mb_substr($v, 0, 120) : '', $request->only(['q', 'status', 'plan', 'cycle', 'sort', 'tenant', 'method', 'from', 'to']));

        [$columns, $rows] = match ($kind) {
            'subscriptions' => [
                ['tenant', 'slug', 'owner_email', 'plan', 'status', 'billing_cycle', 'price_paisa', 'price_taka', 'current_period_start', 'current_period_end', 'trial_ends_at', 'grace_until', 'cancel_at_period_end', 'arrears_paisa', 'arrears_taka', 'arrears_invoices'],
                $this->map($subscriptions->export($filters), fn (array $r): array => [
                    $r['tenant']['name'], $r['tenant']['slug'], $r['tenant']['owner_email'], $r['plan_code'], $r['status'], $r['billing_cycle'],
                    $r['price_paisa'], self::taka($r['price_paisa']), $r['current_period_start'], $r['current_period_end'], $r['trial_ends_at'], $r['grace_until'],
                    $r['cancel_at_period_end'] ? 'yes' : 'no', $r['arrears_paisa'], self::taka($r['arrears_paisa']), $r['arrears_invoices'],
                ]),
            ],
            'invoices' => [
                ['number', 'tenant', 'slug', 'status', 'period_start', 'period_end', 'issued_at', 'due_at', 'paid_at', 'total_paisa', 'total_taka', 'paid_paisa', 'paid_taka', 'due_paisa', 'due_taka', 'dunning_step'],
                $this->map($invoices->export($filters), fn (array $r): array => [
                    $r['number'], $r['tenant']['name'] ?? '', $r['tenant']['slug'] ?? '', $r['status'], $r['period_start'], $r['period_end'], $r['issued_at'], $r['due_at'], $r['paid_at'],
                    $r['total_paisa'], self::taka($r['total_paisa']), $r['paid_paisa'], self::taka($r['paid_paisa']), $r['due_paisa'], self::taka($r['due_paisa']), $r['dunning_step'],
                ]),
            ],
            default => [
                ['payment', 'tenant', 'slug', 'invoice', 'method', 'status', 'amount_paisa', 'amount_taka', 'gateway_txn_id', 'idempotency_key', 'paid_at', 'recorded_by'],
                $this->map($payments->export($filters), fn (array $r): array => [
                    $r['public_id'], $r['tenant']['name'] ?? '', $r['tenant']['slug'] ?? '', $r['invoice']['number'] ?? '', $r['method'], $r['status'],
                    $r['amount_paisa'], self::taka($r['amount_paisa']), $r['gateway_txn_id'], $r['idempotency_key'], $r['paid_at'], $r['recorded_by'],
                ]),
            ],
        };

        $audit->record(CentralAuditAction::Export, null, null, null, ['kind' => 'billing.'.$kind, 'filters' => array_filter($filters, fn (string $v) => $v !== '')]);

        $filename = 'platform-'.$kind.'-'.CarbonImmutable::now('Asia/Dhaka')->format('Ymd-Hi').'.csv';

        return response()->streamDownload(function () use ($columns, $rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns, ',', '"', '\\');

            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($v): string => $v === null ? '' : (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v), $row), ',', '"', '\\');
                flush();
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * @param  Generator<int, array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): array<int, mixed>  $cells
     * @return Generator<int, array<int, mixed>>
     */
    private function map(Generator $rows, callable $cells): Generator
    {
        foreach ($rows as $row) {
            yield $cells($row);
        }
    }

    /** Integer paisa → "1234.50", by integer arithmetic only. */
    private static function taka(int $paisa): string
    {
        $sign = $paisa < 0 ? '-' : '';
        $abs = abs($paisa);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }
}
