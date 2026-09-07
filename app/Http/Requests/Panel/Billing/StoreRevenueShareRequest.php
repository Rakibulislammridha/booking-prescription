<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Billing;

use App\Domain\Billing\Enums\RevenueShareItemType;
use App\Domain\Billing\Enums\RevenueShareType;
use App\Models\Tenant\DoctorRevenueShare;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreRevenueShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $share = $this->route('share');

        return $user instanceof User && ($share instanceof DoctorRevenueShare ? $user->can('update', $share) : $user->can('create', DoctorRevenueShare::class));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'doctor' => ['required', 'string', 'size:26'],
            'branch' => ['nullable', 'string', 'size:26'],
            'item_type' => ['required', Rule::in(RevenueShareItemType::values())],
            'share_type' => ['required', Rule::in(RevenueShareType::values())],
            'share_value' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
