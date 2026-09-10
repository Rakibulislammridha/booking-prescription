<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Plans;

use App\Domain\SaaS\Actions\Plans\ArchivePlan;
use App\Domain\SaaS\Data\PlanData;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Models\Central\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Plan create/update from the super console. Prices arrive as integer PAISA from the client — never taka, never
 * a float — so nothing is rounded on the way in; the taka → paisa step happens in the browser on keystrokes
 * (`parseBdt`) and the wire carries what the database will store.
 *
 * `limits.*` is `nullable|integer`, where `null` means UNLIMITED and `0` means the plan does not include the
 * feature at all. Those two really are different (SCHEMA §2.3), so the field cannot be a plain integer.
 *
 * `acknowledge` is the server half of the "this plan has live subscribers" confirmation: editing a plan that
 * clinics are on changes their entitlements the moment it saves (and their price at the next renewal — the
 * no-proration rule), so the console shows the diff first and the request must say the operator saw it.
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
            'acknowledge' => ['boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(ArchivePlan $archive): array
    {
        return [function (Validator $validator) use ($archive): void {
            $plan = $this->route('plan');

            if (! $plan instanceof Plan || $this->boolean('acknowledge')) {
                return;
            }

            $live = $archive->liveSubscriptions($plan);

            if ($live > 0) {
                $validator->errors()->add('acknowledge', (string) __('super.plans.acknowledge_required', ['count' => (string) $live]));
            }
        }];
    }

    public function toData(): PlanData
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();
        $limitKeys = array_flip(array_map(fn (PlanFeatureKey $k) => $k->value, PlanFeatureKey::limits()));
        $toggleKeys = array_flip(array_map(fn (PlanFeatureKey $k) => $k->value, PlanFeatureKey::toggles()));

        /** @var array<string, int|null> $limits */
        $limits = array_map(fn ($x): ?int => $x === null ? null : (int) $x, array_intersect_key((array) ($v['limits'] ?? []), $limitKeys));
        /** @var array<string, bool> $toggles */
        $toggles = array_map(fn ($x): bool => (bool) $x, array_intersect_key((array) ($v['toggles'] ?? []), $toggleKeys));

        return new PlanData(
            code: (string) $v['code'],
            name: (string) $v['name'],
            description: isset($v['description']) && trim((string) $v['description']) !== '' ? (string) $v['description'] : null,
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
