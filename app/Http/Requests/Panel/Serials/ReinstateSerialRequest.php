<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Serials;

use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;

final class ReinstateSerialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $subject = $this->route('serial');

        return $user instanceof User && $subject !== null && $user->can('reinstate', $subject);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'present' => ['sometimes', 'boolean'],
        ];
    }
}
