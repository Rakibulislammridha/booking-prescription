<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Serials;

use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReorderSerialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $subject = $this->route('serial');

        return $user instanceof User && $subject !== null && $user->can('reorder', $subject);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'after' => ['nullable', 'string', 'size:26', Rule::exists('serials', 'public_id')],
            'before' => ['nullable', 'string', 'size:26', Rule::exists('serials', 'public_id')],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
