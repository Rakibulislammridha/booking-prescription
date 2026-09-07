<?php

declare(strict_types=1);

namespace Database\Seeders\Central;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Models\Central\Plan;
use App\Models\Central\PlanFeature;
use Illuminate\Database\Seeder;

/**
 * Idempotent: upserts by plan code / feature key (never truncates).
 */
final class PlansSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['code' => 'starter', 'name' => 'Starter', 'price_monthly_paisa' => 0, 'price_yearly_paisa' => 0, 'trial_days' => 14, 'sort_order' => 1,
                'features' => ['branches' => 1, 'doctors' => 3, 'appointments_monthly' => 500, 'sms_credits_monthly' => 200, 'storage_bytes' => 1_073_741_824]],
            ['code' => 'basic', 'name' => 'Basic', 'price_monthly_paisa' => 150000, 'price_yearly_paisa' => 1500000, 'trial_days' => 14, 'sort_order' => 2,
                'features' => ['branches' => 2, 'doctors' => 10, 'appointments_monthly' => 3000, 'sms_credits_monthly' => 1000, 'storage_bytes' => 5_368_709_120, 'waiting_room_display' => true]],
            ['code' => 'pro', 'name' => 'Pro', 'price_monthly_paisa' => 400000, 'price_yearly_paisa' => 4000000, 'trial_days' => 14, 'sort_order' => 3,
                'features' => ['branches' => 5, 'doctors' => 50, 'appointments_monthly' => null, 'sms_credits_monthly' => 5000, 'storage_bytes' => 21_474_836_480, 'waiting_room_display' => true, 'whatsapp' => true, 'reports_export' => true, 'custom_domain' => true, 'handwriting_mode' => true]],
            ['code' => 'enterprise', 'name' => 'Enterprise', 'price_monthly_paisa' => 1000000, 'price_yearly_paisa' => 10000000, 'trial_days' => 30, 'sort_order' => 4,
                'features' => ['branches' => null, 'doctors' => null, 'appointments_monthly' => null, 'sms_credits_monthly' => null, 'storage_bytes' => null, 'waiting_room_display' => true, 'whatsapp' => true, 'ivr' => true, 'reports_export' => true, 'custom_domain' => true, 'handwriting_mode' => true, 'ai_assist' => true]],
            ['code' => 'telemedicine', 'name' => 'Telemedicine add-on', 'price_monthly_paisa' => 100000, 'price_yearly_paisa' => 1000000, 'trial_days' => 0, 'sort_order' => 10, 'is_addon' => true,
                'features' => ['telemedicine' => true]],
        ];

        foreach ($plans as $definition) {
            $features = $definition['features'];
            unset($definition['features']);

            $plan = Plan::query()->updateOrCreate(['code' => $definition['code']], $definition + ['is_public' => true, 'is_addon' => false]);

            foreach ($features as $key => $value) {
                PlanFeatureKey::from($key);

                PlanFeature::query()->updateOrCreate(
                    ['plan_id' => $plan->id, 'feature_key' => $key],
                    is_bool($value) ? ['limit_value' => null, 'enabled' => $value] : ['limit_value' => $value, 'enabled' => true],
                );
            }
        }
    }
}
