<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Data;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final readonly class HolidayData
{
    public function __construct(
        public CarbonImmutable $holidayDate,
        public string $name,
        public ?string $nameBn = null,
        public ?int $branchId = null,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();

        return new self(
            holidayDate: CarbonImmutable::parse((string) $v['holiday_date'])->startOfDay(),
            name: (string) $v['name'],
            nameBn: $v['name_bn'] ?? null,
            branchId: isset($v['branch_id']) ? (int) $v['branch_id'] : null,
        );
    }
}
