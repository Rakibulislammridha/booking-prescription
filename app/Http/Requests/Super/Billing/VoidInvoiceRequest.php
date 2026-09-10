<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Billing;

use Illuminate\Foundation\Http\FormRequest;

/** Voiding needs a typed reason: it is the sentence the audit row carries and support reads back. */
final class VoidInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:255']];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
