<?php

declare(strict_types=1);

namespace App\Domain\Reception\Sync;

use App\Domain\Reception\Enums\OfflineEventStatus;
use App\Domain\Reception\Enums\OfflineEventType;
use App\Domain\Shared\Actor;
use App\Models\Tenant\OfflineEvent;
use App\Models\Tenant\Patient;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\Serial;
use App\Models\Tenant\User;

/**
 * Per-batch state (OFFLINE §7.2 c): the device, the human actor, the `local:… → server row` maps for patients (by
 * the stub's localId) and serials (by the issue_serial client_event_id). A reference not mapped in this batch is
 * looked up through the device's accepted offline_events rows, so dependants may arrive in a later batch.
 */
final class ReplayContext
{
    public const LOCAL_PREFIX = 'local:';

    /** @var array<string, Patient> */
    private array $patients = [];

    /** @var array<string, Serial> */
    private array $serials = [];

    public function __construct(
        public readonly ReceptionDevice $device,
        public readonly User $actor,
        public readonly Actor $actorDto,
    ) {}

    public function mapPatient(string $localId, Patient $patient): void
    {
        $this->patients[$localId] = $patient;
    }

    public function mapSerial(string $clientEventId, Serial $serial): void
    {
        $this->serials[$clientEventId] = $serial;
    }

    /** `pat` public id or `local:<localId>` (a register_patient stub of this device). */
    public function patientRef(?string $ref): ?Patient
    {
        if ($ref === null || $ref === '') {
            return null;
        }

        if (! str_starts_with($ref, self::LOCAL_PREFIX)) {
            return Patient::query()->where('public_id', $ref)->first();
        }

        $localId = substr($ref, strlen(self::LOCAL_PREFIX));

        if (isset($this->patients[$localId])) {
            return $this->patients[$localId];
        }

        $row = OfflineEvent::query()
            ->where('reception_device_id', $this->device->id)
            ->where('type', OfflineEventType::RegisterPatient->value)
            ->where('status', OfflineEventStatus::Accepted->value)
            ->where('payload->localId', $localId)
            ->orderByDesc('id')
            ->first();

        $publicId = $row->server_result['patient']['public_id'] ?? null;
        $patient = is_string($publicId) ? Patient::query()->where('public_id', $publicId)->first() : null;

        if ($patient !== null) {
            $this->patients[$localId] = $patient;
        }

        return $patient;
    }

    /** `ser` public id or `local:<clientEventId>` (an issue_serial event of this device). */
    public function serialRef(?string $ref): ?Serial
    {
        if ($ref === null || $ref === '') {
            return null;
        }

        if (! str_starts_with($ref, self::LOCAL_PREFIX)) {
            return Serial::query()->where('public_id', $ref)->first();
        }

        $clientEventId = substr($ref, strlen(self::LOCAL_PREFIX));

        if (isset($this->serials[$clientEventId])) {
            return $this->serials[$clientEventId]->refresh();
        }

        $serial = Serial::query()->where('reception_device_id', $this->device->id)->where('client_event_id', $clientEventId)->first();

        if ($serial === null) {
            $row = OfflineEvent::query()->where('reception_device_id', $this->device->id)->where('client_event_id', $clientEventId)->where('status', OfflineEventStatus::Accepted->value)->first();
            $publicId = $row->server_result['serial']['public_id'] ?? null;
            $serial = is_string($publicId) ? Serial::query()->where('public_id', $publicId)->first() : null;
        }

        if ($serial !== null) {
            $this->serials[$clientEventId] = $serial;
        }

        return $serial;
    }

    /** Seed the maps from an already accepted row (a dependency processed in an earlier batch or earlier in this one). */
    public function prime(OfflineEvent $row): void
    {
        if ($row->status !== OfflineEventStatus::Accepted) {
            return;
        }

        $result = $row->server_result ?? [];

        if ($row->type === OfflineEventType::RegisterPatient) {
            $localId = (string) ($row->payload['localId'] ?? '');
            $publicId = $result['patient']['public_id'] ?? null;

            if ($localId !== '' && is_string($publicId) && ! isset($this->patients[$localId])) {
                $patient = Patient::query()->where('public_id', $publicId)->first();

                if ($patient !== null) {
                    $this->patients[$localId] = $patient;
                }
            }
        }

        if ($row->type === OfflineEventType::IssueSerial) {
            $publicId = $result['serial']['public_id'] ?? null;

            if (is_string($publicId) && ! isset($this->serials[$row->client_event_id])) {
                $serial = Serial::query()->where('public_id', $publicId)->first();

                if ($serial !== null) {
                    $this->serials[$row->client_event_id] = $serial;
                }
            }
        }
    }
}
