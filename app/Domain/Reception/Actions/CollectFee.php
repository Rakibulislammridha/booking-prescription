<?php

declare(strict_types=1);

namespace App\Domain\Reception\Actions;

use App\Domain\Booking\Enums\PaymentStatus;
use App\Domain\Reception\Contracts\CashCollector;
use App\Domain\Reception\Data\CashCollection;
use App\Domain\Reception\Exceptions\FeeAlreadyCollected;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use Illuminate\Support\Facades\DB;

/** Desk "collect fee" (online): a receipt number is minted when the desk sends none (`C-{appointment.public_id tail}`). */
final class CollectFee
{
    public function __construct(private readonly CashCollector $collector) {}

    public function handle(Appointment $appointment, ?int $amountPaisa, Actor $actor, ?string $receiptNo = null, ?string $note = null): CashCollection
    {
        return DB::transaction(function () use ($appointment, $amountPaisa, $actor, $receiptNo, $note): CashCollection {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($locked->payment_status === PaymentStatus::Paid && $receiptNo === null) {
                throw new FeeAlreadyCollected;
            }

            return $this->collector->collect($locked, $amountPaisa ?? $locked->fee_paisa, $receiptNo ?? self::receiptNo($locked), $actor, null, $note);
        });
    }

    public static function receiptNo(Appointment $appointment): string
    {
        return 'C-'.substr($appointment->public_id, -8);
    }
}
