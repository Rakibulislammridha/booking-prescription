<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Events;

use App\Domain\Queue\TenantChannel;
use App\Models\Tenant\Prescription;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast on tenant.{tenantPublicId}.prescription.{prescriptionPublicId} when the PDF exists (PRESCRIPTION.md §7.5).
 * Dispatched by the P3 GeneratePrescriptionPdf job; the class lives here so the channel inventory is complete.
 */
final class PdfReady implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly int $prescriptionId, public readonly string $path, public readonly ?string $prescriptionPublicId = null) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $rx = Prescription::query()->find($this->prescriptionId);

        return $rx === null ? [] : [TenantChannel::prescription($rx)];
    }

    public function broadcastAs(): string
    {
        return 'pdf.ready';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['prescription_id' => $this->prescriptionPublicId, 'path' => basename($this->path)];
    }
}
