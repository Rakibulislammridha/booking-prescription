<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\PaymentTxnStatus;
use Carbon\CarbonImmutable;

/**
 * One money-in event. `idempotencyKey` is mandatory and is a UNIQUE column: it is what makes a retried request,
 * a replayed offline event and a re-delivered gateway webhook land on the same row instead of charging twice.
 */
final readonly class PaymentRequest
{
    /** @param array<string, mixed> $gatewayPayload */
    public function __construct(
        public PaymentMethod $method,
        public int $amountPaisa,
        public string $idempotencyKey,
        public PaymentTxnStatus $status = PaymentTxnStatus::Succeeded,
        public ?string $receiptNumber = null,
        public ?PaymentGateway $gateway = null,
        public ?string $gatewayTxnId = null,
        public ?string $gatewayPaymentRef = null,
        public array $gatewayPayload = [],
        public ?string $clientEventId = null,
        public ?int $receptionDeviceId = null,
        public ?int $cashShiftId = null,
        public ?CarbonImmutable $paidAt = null,
        public ?string $failedReason = null,
    ) {}

    public function withStatus(PaymentTxnStatus $status): self
    {
        return new self($this->method, $this->amountPaisa, $this->idempotencyKey, $status, $this->receiptNumber, $this->gateway, $this->gatewayTxnId, $this->gatewayPaymentRef, $this->gatewayPayload, $this->clientEventId, $this->receptionDeviceId, $this->cashShiftId, $this->paidAt, $this->failedReason);
    }
}
