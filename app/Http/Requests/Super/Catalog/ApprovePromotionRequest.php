<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Catalog;

use App\Domain\Catalog\Data\PromotionDecision;
use App\Domain\Catalog\Rules\CatalogIdExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /catalog/promotions/{promotion}/approve {mode: map|create, brand_id?, manufacturer?, presentations?, note?} */
final class ApprovePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['map', 'create'])],
            'brand_id' => ['required_if:mode,map', 'nullable', 'integer', new CatalogIdExists('brands', requireActive: false)],
            'manufacturer' => ['nullable', 'string', 'max:160'],
            'presentations' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function toData(): PromotionDecision
    {
        return PromotionDecision::fromRequest($this);
    }
}
