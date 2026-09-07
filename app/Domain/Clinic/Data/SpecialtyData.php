<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Data;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final readonly class SpecialtyData
{
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $nameBn = null,
        public ?string $icon = null,
        public int $sortOrder = 0,
        public bool $isActive = true,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();

        return new self(
            name: (string) $v['name'],
            slug: (string) ($v['slug'] ?? Str::slug((string) $v['name'])),
            nameBn: $v['name_bn'] ?? null,
            icon: $v['icon'] ?? null,
            sortOrder: (int) ($v['sort_order'] ?? 0),
            isActive: (bool) ($v['is_active'] ?? true),
        );
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return ['name' => $this->name, 'name_bn' => $this->nameBn, 'slug' => $this->slug, 'icon' => $this->icon, 'sort_order' => $this->sortOrder, 'is_active' => $this->isActive];
    }
}
