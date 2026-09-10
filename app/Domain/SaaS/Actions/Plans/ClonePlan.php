<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Plans;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Services\CentralAudit;
use App\Models\Central\Plan;
use App\Models\Central\PlanFeature;
use Illuminate\Support\Facades\DB;

/**
 * "Clone plan": a new, HIDDEN copy of a tier with every feature row duplicated, so a new tier is edited from a
 * known-good starting point instead of typed in from scratch. Hidden (`is_public = false`) because a half-edited
 * copy must never reach the pricing page by accident; the operator publishes it when it is ready.
 *
 * The code is `<code>-copy`, `<code>-copy-2`, … — unique by construction, and something a human can read in
 * the audit log without a lookup.
 */
final class ClonePlan
{
    public function __construct(private readonly CentralAudit $audit) {}

    public function handle(Plan $source, ?string $code = null, ?string $name = null): Plan
    {
        $code ??= $this->nextCode($source->code);
        $name ??= $source->name.' (copy)';

        $clone = DB::connection('pgsql')->transaction(function () use ($source, $code, $name): Plan {
            $clone = new Plan;
            $clone->forceFill([
                'code' => $code,
                'name' => mb_substr($name, 0, 80),
                'description' => $source->description,
                'price_monthly_paisa' => $source->price_monthly_paisa,
                'price_yearly_paisa' => $source->price_yearly_paisa,
                'trial_days' => $source->trial_days,
                'is_public' => false,
                'is_addon' => $source->is_addon,
                'sort_order' => $source->sort_order + 1,
                'archived_at' => null,
            ])->save();

            foreach ($source->features()->orderBy('id')->get() as $feature) {
                PlanFeature::query()->create([
                    'plan_id' => $clone->id,
                    'feature_key' => $feature->feature_key->value,
                    'limit_value' => $feature->limit_value,
                    'enabled' => $feature->enabled,
                ]);
            }

            return $clone;
        });

        $this->audit->record(CentralAuditAction::Create, null, $clone, ['cloned_from' => $source->code], $clone->only(['code', 'name', 'price_monthly_paisa', 'price_yearly_paisa', 'trial_days', 'is_public', 'is_addon', 'sort_order']));

        return $clone->refresh();
    }

    private function nextCode(string $base): string
    {
        $stem = mb_substr($base, 0, 30).'-copy';
        $candidate = $stem;
        $n = 1;

        while (Plan::query()->where('code', $candidate)->exists()) {
            $n++;
            $candidate = $stem.'-'.$n;
        }

        return $candidate;
    }
}
