<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Data;

/**
 * A plan as the super console edits it: the sellable tier plus the complete set of limits and toggles that define
 * what it includes. Features arrive as one map so saving is a replace, not a merge — a key removed in the form is
 * a key removed from the plan, and "unlimited" has to be expressible as `null` rather than as "absent".
 */
final readonly class PlanData
{
    /**
     * @param  array<string, int|null>  $limits  feature_key => cap (null = unlimited)
     * @param  array<string, bool>  $toggles  feature_key => enabled
     */
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description,
        public int $priceMonthlyPaisa,
        public int $priceYearlyPaisa,
        public int $trialDays,
        public bool $isPublic,
        public bool $isAddon,
        public int $sortOrder,
        public array $limits = [],
        public array $toggles = [],
    ) {}
}
