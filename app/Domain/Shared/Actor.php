<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Who did this — the DTO every write action receives (ARCHITECTURE §5.3).
 */
final readonly class Actor
{
    public function __construct(
        public ?int $userId = null,
        public ?string $role = null,
        public ?int $deviceId = null,
        public ?int $patientId = null,
        public ?int $superAdminId = null,
        public ?string $ip = null,
        public string $source = 'system',      // web|api|offline_replay|system
    ) {}

    public static function system(): self
    {
        return new self(source: 'system');
    }

    public static function fromRequest(?Request $request = null): self
    {
        $request ??= request();
        $user = Auth::guard('web')->user();

        /** @var User|null $user */
        $role = $user?->getRoleNames()->first();

        return new self(
            userId: $user?->getKey(),
            role: $role !== null ? (string) $role : null,
            patientId: Auth::guard('patient')->id() !== null ? (int) Auth::guard('patient')->id() : null,
            superAdminId: Auth::guard('super')->id() !== null ? (int) Auth::guard('super')->id() : null,
            ip: $request->ip(),
            source: $request->is('api/*') ? 'api' : 'web',
        );
    }

    public static function user(int $userId, ?string $role = null, ?string $ip = null): self
    {
        return new self(userId: $userId, role: $role, ip: $ip, source: 'web');
    }
}
