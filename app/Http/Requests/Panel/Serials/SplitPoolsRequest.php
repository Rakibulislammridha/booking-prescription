<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Serials;

use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;

final class SplitPoolsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $subject = $this->route('session');

        return $user instanceof User && $subject !== null && $user->can('adjustSplit', $subject);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'counter_quota' => ['required', 'integer', 'between:0,999'],
            'online_quota' => ['required', 'integer', 'between:0,999'],
            'buffer_quota' => ['nullable', 'integer', 'between:0,999'],
        ];
    }
}
