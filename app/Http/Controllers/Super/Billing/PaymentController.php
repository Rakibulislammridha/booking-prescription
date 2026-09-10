<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Billing;

use App\Domain\SaaS\Enums\SubscriptionPaymentMethod;
use App\Domain\SaaS\Enums\SubscriptionPaymentStatus;
use App\Domain\SaaS\Queries\BillingPayments;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Every payment the platform received or attempted, with its gateway reference — the bank-reconciliation list. */
final class PaymentController extends Controller
{
    public function __invoke(Request $request, BillingPayments $payments): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'method' => ['nullable', Rule::in(SubscriptionPaymentMethod::values())],
            'status' => ['nullable', Rule::in(SubscriptionPaymentStatus::values())],
            'tenant' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $filters = [
            'q' => (string) ($validated['q'] ?? ''),
            'method' => (string) ($validated['method'] ?? ''),
            'status' => (string) ($validated['status'] ?? ''),
            'tenant' => (string) ($validated['tenant'] ?? ''),
            'from' => (string) ($validated['from'] ?? ''),
            'to' => (string) ($validated['to'] ?? ''),
        ];
        $page = $payments->list($filters);

        return Inertia::render('Super/Billing/Payments', [
            'payments' => $page['data'],
            'meta' => $page['meta'],
            'filters' => $filters,
            'methods' => SubscriptionPaymentMethod::values(),
            'statuses' => SubscriptionPaymentStatus::values(),
            'method_totals' => $payments->methodTotals(),
        ]);
    }
}
