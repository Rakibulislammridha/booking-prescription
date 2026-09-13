<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Billing;

use App\Domain\Billing\Actions\CreateInvoiceForAppointment;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\VoidInvoice;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Gateways\GatewayManager;
use App\Domain\Billing\Queries\OutstandingDuesQuery;
use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Services\ActiveBranch;
use App\Domain\Clinic\Services\DoctorScope;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\InvoiceResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Billing/Invoices (list + filters) and Billing/Invoice (detail with payments, refunds and actions).
 *
 * `billing.invoices.view` is clinic-wide, so the list is filtered by the caller's DoctorScope the way every row is
 * filtered by InvoicePolicy::view: a compounder reads the bills of the doctors they work for, and the outstanding
 * total above the list is the same population as the list — an unfiltered headline over a filtered table is a leak
 * with a number on it.
 */
final class InvoiceController extends Controller
{
    public function index(Request $request, ActiveBranch $activeBranch, OutstandingDuesQuery $dues, DoctorScope $scope): Response
    {
        $this->authorize('viewAny', Invoice::class);
        /** @var User $user */
        $user = $request->user('web');
        $doctorIds = $scope->doctorIds($user);

        $status = (string) $request->query('status', '');
        $q = trim((string) $request->query('q', ''));
        $branch = $activeBranch->current();

        $invoices = Invoice::query()
            ->with(['patient', 'doctor', 'branch'])
            ->when($branch !== null, fn (Builder $b) => $b->where('branch_id', $branch->id))
            ->when($doctorIds !== null, fn (Builder $b) => $b->whereIn('doctor_id', $doctorIds ?? []))
            ->when($status !== '' && in_array($status, InvoiceStatus::values(), true), fn (Builder $b) => $b->where('status', $status))
            ->when($status === 'due', fn (Builder $b) => $b->outstanding())
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $w) => $w
                ->where('number', 'ilike', "%{$q}%")
                ->orWhereHas('patient', fn (Builder $p) => $p->where('name', 'ilike', "%{$q}%")->orWhere('mobile', 'ilike', "%{$q}%")->orWhere('patient_code', 'ilike', "%{$q}%"))))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Billing/Invoices', [
            'filters' => ['status' => $status, 'q' => $q],
            'invoices' => InvoiceResource::collection($invoices)->response()->getData(true),
            'outstanding_paisa' => $dues->totalPaisa($branch?->id, null, $doctorIds),
            'statuses' => InvoiceStatus::values(),
            'can' => $this->abilities($user),
        ]);
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        $this->authorize('view', $invoice);
        /** @var User $user */
        $user = $request->user('web');

        $invoice->load(['patient', 'doctor', 'branch', 'appointment', 'items', 'payments.receivedBy', 'refunds.payment', 'discounts']);
        AuditLog::view($invoice, ['screen' => 'billing.invoice']);

        return Inertia::render('Billing/Invoice', [
            'invoice' => (new InvoiceResource($invoice))->resolve(),
            'gateways' => array_map(fn ($g) => $g->value, app(GatewayManager::class)->available()),
            'can' => $this->abilities($user) + [
                'refund' => $user->can('refund', $invoice),
                'void' => $user->can('void', $invoice),
                'issue' => $user->can('issue', $invoice),
            ],
        ]);
    }

    /**
     * Create (or find) the bill for a booking and open it — the desk's "make a bill" button.
     *
     * TWO checks, because the first one cannot carry the scope. `create` is class-level (`billing.payments.collect`,
     * no row to narrow by), and the row this request actually acts on arrives in the body as a public id: without
     * the second check any holder of the permission could raise AND issue a bill against any booking in the tenant
     * and be redirected onto the invoice screen with it. Every other invoice ability grew a DoctorScope conjunct in
     * this wave; for `store` the conjunct belongs on the resolved appointment, which is why it is asked here rather
     * than in the policy. `collect` is the ability the desk's own fee button asks for the same booking
     * (Reception\CollectFeeRequest), so this is the panel's existing rule, applied one step earlier.
     */
    public function store(Request $request, CreateInvoiceForAppointment $create, IssueInvoice $issue): RedirectResponse
    {
        $this->authorize('create', Invoice::class);
        $appointment = Appointment::query()->where('public_id', (string) $request->input('appointment'))->firstOrFail();
        $this->authorize('collect', $appointment);

        $invoice = $create->handle($appointment, Actor::fromRequest($request));

        if ($invoice->isDraft()) {
            $invoice = $issue->handle($invoice, Actor::fromRequest($request));
        }

        return redirect()->route('panel.billing.invoices.show', ['invoice' => $invoice->public_id])->with('flash.success', __('billing.flash.invoice_created'));
    }

    public function issue(Request $request, Invoice $invoice, IssueInvoice $action): RedirectResponse
    {
        $this->authorize('issue', $invoice);
        $action->handle($invoice, Actor::fromRequest($request));

        return back()->with('flash.success', __('billing.flash.invoice_issued'));
    }

    public function void(Request $request, Invoice $invoice, VoidInvoice $action): RedirectResponse
    {
        $this->authorize('void', $invoice);
        $action->handle($invoice, (string) $request->input('reason', __('billing.void.reason.manual')), Actor::fromRequest($request));

        return back()->with('flash.success', __('billing.flash.invoice_voided'));
    }

    /** @return array<string, bool> */
    private function abilities(User $user): array
    {
        return [
            'collect' => $user->can(Permission::BillingPaymentsCollect->value),
            'discount' => $user->can(Permission::BillingPaymentsCollect->value),
            'approve_discount' => $user->can(Permission::BillingDiscountsApprove->value),
            'refund_any' => $user->can(Permission::BillingRefundsIssue->value),
            'reports' => $user->can(Permission::BillingReportsView->value),
        ];
    }
}
