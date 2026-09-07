<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Serials;

use App\Domain\Serials\Enums\SerialPriority;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PrioritySerialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $subject = $this->route('serial');

        return $user instanceof User && $subject !== null && $user->can('priority', $subject);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'priority' => ['required', Rule::enum(SerialPriority::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
