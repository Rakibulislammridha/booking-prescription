<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\InvoiceLineData;
use App\Domain\Billing\Enums\InvoiceItemType;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Services\InvoiceLedger;
use App\Domain\Booking\Enums\AppointmentType;
use App\Domain\Booking\Enums\FeeRule;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The consultation invoice for one booking (BRIEF §5.I). The fee is COPIED from the appointment's snapshot
 * (`fee_paisa`, `fee_rule`, `fee_rule_reason` — SCHEMA §5.10); Billing never re-derives it, so the free
 * follow-up window Booking already decided is what the invoice and the money receipt say.
 *
 * Idempotent twice over: it returns any existing live invoice, and `invoices_appointment_id_uniq_p` makes a
 * racing second call fail at the database and re-read the winner. A retry can never mint a second bill.
 */
final class CreateInvoiceForAppointment
{
    public function __construct(private readonly InvoiceLedger $ledger) {}

    public function handle(Appointment $appointment, Actor $actor, ?int $cashShiftId = null): Invoice
    {
        $existing = $this->liveInvoiceFor($appointment);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn (): Invoice => $this->create($appointment, $actor, $cashShiftId));
        } catch (QueryException $e) {
            // Lost the race on invoices_appointment_id_uniq_p: the winner's invoice is the one true bill.
            $winner = $this->liveInvoiceFor($appointment);

            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }
    }

    private function create(Appointment $appointment, Actor $actor, ?int $cashShiftId): Invoice
    {
        $invoice = new Invoice;
        $invoice->forceFill([
            'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->id,
            'visit_id' => null,
            'doctor_id' => $appointment->doctor_id,
            'branch_id' => $appointment->branch_id,
            'status' => InvoiceStatus::Draft,
            'created_by_user_id' => $actor->userId,
            'cash_shift_id' => $cashShiftId,
            'notes' => $appointment->fee_rule_reason,
        ])->save();

        $line = $this->consultationLine($appointment);

        $item = new InvoiceItem;
        $item->forceFill([
            'invoice_id' => $invoice->id,
            'sort_order' => 1,
            'type' => $line->type,
            'description' => $line->description,
            'reference_type' => $line->referenceType,
            'reference_id' => $line->referenceId,
            'doctor_id' => $line->doctorId,
            'quantity' => $line->quantity,
            'unit_price_paisa' => $line->unitPricePaisa,
            'line_total_paisa' => $line->quantity * $line->unitPricePaisa,
        ])->save();

        return $this->ledger->sync($invoice->refresh());
    }

    /** The billed description carries the fee rule, so the receipt explains a ৳0 line by itself. */
    public function consultationLine(Appointment $appointment): InvoiceLineData
    {
        $type = match (true) {
            $appointment->is_telemedicine || $appointment->fee_rule === FeeRule::Telemedicine => InvoiceItemType::Telemedicine,
            $appointment->type === AppointmentType::Followup => InvoiceItemType::Followup,
            default => InvoiceItemType::Consultation,
        };

        $description = match ($type) {
            InvoiceItemType::Telemedicine => __('billing.line.telemedicine'),
            InvoiceItemType::Followup => __('billing.line.followup'),
            default => __('billing.line.consultation'),
        };

        return new InvoiceLineData(
            type: $type,
            description: $description,
            unitPricePaisa: $appointment->fee_paisa,
            quantity: 1,
            doctorId: $appointment->doctor_id,
            referenceType: $appointment->getMorphClass(),
            referenceId: $appointment->id,
        );
    }

    private function liveInvoiceFor(Appointment $appointment): ?Invoice
    {
        return Invoice::query()->where('appointment_id', $appointment->id)->live()->first();
    }
}
