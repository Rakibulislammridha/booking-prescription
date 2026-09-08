<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\SaaS;

use App\Domain\SaaS\Data\PlanData;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Models\Central\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Plan CRUD from the super console. Prices arrive as integer PAISA from the client — never taka, never a float —
 * so nothing is rounded on the way in.
 *
 * `limits.*` is `nullable|integer`, where `null` means UNLIMITED and `0` means the plan does not include the
 * feature at all. Those two really are different (§2.3), so the field cannot be a plain integer.
 */
final class SavePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $plan = $this->route('plan');
        $ignore = $plan instanceof Plan ? $plan->id : null;

        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9_-]{1,39}$/', Rule::unique(Plan::class, 'code')->ignore($ignore)],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_monthly_paisa' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'price_yearly_paisa' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'is_public' => ['boolean'],
            'is_addon' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:32000'],
            'limits' => ['array'],
            'limits.*' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'toggles' => ['array'],
            'toggles.*' => ['boolean'],
        ];
    }

    public function toData(): PlanData
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();
        $limitKeys = array_flip(array_map(fn (PlanFeatureKey $k) => $k->value, PlanFeatureKey::limits()));
        $toggleKeys = array_flip(array_map(fn (PlanFeatureKey $k) => $k->value, PlanFeatureKey::toggles()));

        /** @var array<string, int|null> $limits */
        $limits = array_intersect_key((array) ($v['limits'] ?? []), $limitKeys);
        /** @var array<string, bool> $toggles */
        $toggles = array_map(fn ($x): bool => (bool) $x, array_intersect_key((array) ($v['toggles'] ?? []), $toggleKeys));

        return new PlanData(
            code: (string) $v['code'],
            name: (string) $v['name'],
            description: isset($v['description']) ? (string) $v['description'] : null,
            priceMonthlyPaisa: (int) $v['price_monthly_paisa'],
            priceYearlyPaisa: (int) $v['price_yearly_paisa'],
            trialDays: (int) $v['trial_days'],
            isPublic: (bool) ($v['is_public'] ?? true),
            isAddon: (bool) ($v['is_addon'] ?? false),
            sortOrder: (int) $v['sort_order'],
            limits: $limits,
            toggles: $toggles,
        );
    }
}
