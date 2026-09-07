<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Data;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final readonly class BranchData
{
    /**
     * @param  array<string, mixed>|null  $geo
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public string $name,
        public string $code,
        public string $slug,
        public ?string $address = null,
        public ?string $phone = null,
        public ?string $email = null,
        public bool $isMain = false,
        public bool $isActive = true,
        public ?array $geo = null,
        public array $settings = [],
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();

        return new self(
            name: (string) $v['name'],
            code: strtoupper((string) $v['code']),
            slug: (string) ($v['slug'] ?? Str::slug((string) $v['name'])),
            address: $v['address'] ?? null,
            phone: $v['phone'] ?? null,
            email: $v['email'] ?? null,
            isMain: (bool) ($v['is_main'] ?? false),
            isActive: (bool) ($v['is_active'] ?? true),
            geo: $v['geo'] ?? null,
            settings: $v['settings'] ?? [],
        );
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name, 'code' => $this->code, 'slug' => $this->slug, 'address' => $this->address, 'phone' => $this->phone,
            'email' => $this->email, 'is_main' => $this->isMain, 'is_active' => $this->isActive, 'geo' => $this->geo, 'settings' => $this->settings,
        ];
    }
}
