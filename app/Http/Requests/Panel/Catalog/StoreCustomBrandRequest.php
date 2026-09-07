<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Catalog;

use App\Domain\Catalog\Data\CustomBrandData;
use App\Domain\Catalog\Rules\CatalogIdExists;
use App\Models\Tenant\CustomBrand;
use Illuminate\Foundation\Http\FormRequest;

/** generic_id is required and must resolve to an active catalog generic — a brand without a molecule cannot be saved (BRIEF §3.4). */
final class StoreCustomBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', CustomBrand::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'brand_name' => ['required', 'string', 'max:160'],
            'generic_id' => ['required', 'integer', new CatalogIdExists('generics')],
            'manufacturer' => ['nullable', 'string', 'max:160'],
            'strength' => ['nullable', 'string', 'max:64'],
            'dosage_form_id' => ['nullable', 'integer', new CatalogIdExists('dosage_forms')],
            'route_id' => ['nullable', 'integer', new CatalogIdExists('routes')],
        ];
    }

    public function toData(): CustomBrandData
    {
        return CustomBrandData::fromRequest($this);
    }
}
