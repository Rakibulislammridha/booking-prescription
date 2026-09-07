<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Reception;

use App\Domain\Reception\Enums\OfflineEventType;
use App\Domain\Reception\Sync\SyncReplayer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/reception/sync (OFFLINE §7.1): ≤ 200 events; ordering is re-checked by the replayer (422 on violation). */
final class SyncRequest extends FormRequest
{
    public const ULID = 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/i';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'sequence_no_from' => ['nullable', 'integer', 'min:0'],
            'app_version' => ['nullable', 'string', 'max:20'],
            'events' => ['present', 'array', 'max:'.SyncReplayer::MAX_BATCH],
            'events.*.client_event_id' => ['required', 'string', 'size:26', self::ULID],
            'events.*.sequence_no' => ['required', 'integer', 'min:0'],
            'events.*.type' => ['required', Rule::in(OfflineEventType::supported())],
            'events.*.client_occurred_at' => ['required', 'date'],
            'events.*.actor_user_id' => ['nullable', 'string', 'max:26'],
            'events.*.depends_on' => ['nullable', 'string', 'size:26', self::ULID],
            'events.*.session_id' => ['nullable', 'string', 'size:26'],
            'events.*.payload' => ['present', 'array'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function events(): array
    {
        /** @var array<int, array<string, mixed>> $events */
        $events = $this->validated('events', []);

        return array_map(fn (array $e) => $e + ['client_event_id' => strtoupper((string) $e['client_event_id'])], $events);
    }
}
