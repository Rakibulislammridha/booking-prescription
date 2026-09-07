<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Catalog;

use Illuminate\Foundation\Http\FormRequest;

final class RejectPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:255']];
    }
}
