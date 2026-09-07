<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Serials;

use App\Domain\Serials\Data\AllocationRequest;
use App\Domain\Serials\Enums\SerialPriority;
use App\Domain\Serials\Enums\SerialSource;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /sessions/{session}/serials — counter, walk-in (buffer) and staff follow-up issuing (SERIAL_ENGINE §16). */
final class StoreSerialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $session = $this->route('session');

        return $user instanceof User && $session instanceof SessionInstance && $user->can('issue', $session);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'source' => ['required', Rule::in([SerialSource::Counter->value, SerialSource::Walkin->value, SerialSource::Followup->value])],
            'patient_id' => ['nullable', 'integer', 'min:1'],
            'appointment_id' => ['nullable', 'integer', 'min:1'],
            'priority' => ['sometimes', Rule::enum(SerialPriority::class)],
            'priority_reason' => ['nullable', 'string', 'max:255'],
            'slot_start_at' => ['nullable', 'date'],
            'client_event_id' => ['nullable', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/'],
        ];
    }

    public function toData(): AllocationRequest
    {
        $v = $this->validated();
        /** @var SessionInstance $session */
        $session = $this->route('session');
        $source = SerialSource::from((string) $v['source']);

        return new AllocationRequest(
            sessionInstanceId: $session->id,
            pool: $source->defaultPool(staffActor: true),
            source: $source,
            priority: SerialPriority::from((string) ($v['priority'] ?? SerialPriority::Normal->value)),
            patientId: isset($v['patient_id']) ? (int) $v['patient_id'] : null,
            appointmentId: isset($v['appointment_id']) ? (int) $v['appointment_id'] : null,
            clientEventId: $v['client_event_id'] ?? null,
            actorUserId: $this->user() instanceof User ? $this->user()->id : null,
            slotStartAt: isset($v['slot_start_at']) ? CarbonImmutable::parse((string) $v['slot_start_at'])->utc() : null,
            priorityReason: $v['priority_reason'] ?? null,
        );
    }
}
