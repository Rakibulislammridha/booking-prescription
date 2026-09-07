<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Data;

use App\Domain\Clinic\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

final readonly class StaffUserData
{
    public function __construct(
        public string $name,
        public string $email,
        public Role $role,
        public ?string $password = null,
        public ?string $mobile = null,
        public ?int $defaultBranchId = null,
        public string $locale = 'bn',
        public bool $isActive = true,
        public bool $mustChangePassword = false,
        public ?int $sessionTimeoutMinutes = null,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();

        return new self(
            name: (string) $v['name'],
            email: strtolower((string) $v['email']),
            role: Role::from((string) $v['role']),
            password: $v['password'] ?? null,
            mobile: $v['mobile'] ?? null,
            defaultBranchId: isset($v['default_branch_id']) ? (int) $v['default_branch_id'] : null,
            locale: (string) ($v['locale'] ?? 'bn'),
            isActive: (bool) ($v['is_active'] ?? true),
            mustChangePassword: (bool) ($v['must_change_password'] ?? false),
            sessionTimeoutMinutes: isset($v['session_timeout_minutes']) ? (int) $v['session_timeout_minutes'] : null,
        );
    }
}
