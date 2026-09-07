<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Reception;

use App\Domain\Reception\Enums\ConflictResolution;
use App\Domain\Reception\Sync\Resolution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/reception/sync/resolve {client_event_id, resolution, params} (OFFLINE §7.4). */
final class ResolveConflictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'client_event_id' => ['required', 'string', 'size:26', SyncRequest::ULID],
            'resolution' => ['required', Rule::enum(ConflictResolution::class)],
            'params' => ['sometimes', 'array'],
            'params.patient' => ['nullable', 'string', 'size:26'],
            'params.holder_patient' => ['nullable', 'string', 'size:26'],
            'params.session' => ['nullable', 'string', 'size:26'],
            'params.reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function toData(): Resolution
    {
        /** @var array<string, mixed> $params */
        $params = $this->validated('params', []);

        return new Resolution(ConflictResolution::from((string) $this->validated('resolution')), $params);
    }
}
