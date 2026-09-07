<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Data;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final readonly class DepartmentData
{
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $nameBn = null,
        public ?int $branchId = null,
        public int $sortOrder = 0,
        public bool $isActive = true,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();

        return new self(
            name: (string) $v['name'],
            slug: (string) ($v['slug'] ?? Str::slug((string) $v['name'])),
            nameBn: isset($v['name_bn']) && (string) $v['name_bn'] !== '' ? (string) $v['name_bn'] : null,
            branchId: isset($v['branch_id']) ? (int) $v['branch_id'] : null,
            sortOrder: (int) ($v['sort_order'] ?? 0),
            isActive: (bool) ($v['is_active'] ?? true),
        );
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return ['name' => $this->name, 'name_bn' => $this->nameBn, 'slug' => $this->slug, 'branch_id' => $this->branchId, 'sort_order' => $this->sortOrder, 'is_active' => $this->isActive];
    }
}
