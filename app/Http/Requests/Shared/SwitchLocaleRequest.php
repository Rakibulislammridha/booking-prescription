<?php

declare(strict_types=1);

namespace App\Http\Requests\Shared;

use App\Domain\Clinic\Enums\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PATCH /locale and PATCH /panel/locale — {locale: bn|en}. Anyone may switch language. */
final class SwitchLocaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['locale' => ['required', 'string', Rule::in(Locale::values())]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'locale.required' => __('validation.locale.required'),
            'locale.in' => __('validation.locale.invalid'),
        ];
    }

    public function locale(): Locale
    {
        return Locale::from((string) $this->string('locale'));
    }
}
