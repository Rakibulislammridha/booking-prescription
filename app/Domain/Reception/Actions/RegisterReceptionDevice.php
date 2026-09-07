<?php

declare(strict_types=1);

namespace App\Domain\Reception\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Reception\Enums\DeviceKind;
use App\Domain\Reception\Enums\DeviceStatus;
use App\Domain\Reception\Events\DeviceRegistered;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Branch;
use App\Models\Tenant\ReceptionDevice;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * OFFLINE §2.1: create (or re-register by fingerprint, rotating the token) a device and issue its Sanctum token with
 * the four reception abilities, 90-day expiry. `number` is the small per-branch receipt prefix (`D2-000123`).
 */
final class RegisterReceptionDevice
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return array{device: ReceptionDevice, token: NewAccessToken, rotated: bool} */
    public function handle(Branch $branch, string $name, string $fingerprint, DeviceKind $kind, Actor $actor, ?string $appVersion = null, ?string $userAgent = null, ?int $blockSize = null): array
    {
        return DB::transaction(function () use ($branch, $name, $fingerprint, $kind, $actor, $appVersion, $userAgent, $blockSize): array {
            $existing = ReceptionDevice::query()->where('device_fingerprint', $fingerprint)->lockForUpdate()->first();

            if ($existing !== null) {
                // Re-registration of the same fingerprint rotates the token and revokes the old one.
                $existing->tokens()->delete();
                $existing->forceFill(array_filter([
                    'name' => $name,
                    'branch_id' => $branch->id,
                    'kind' => $kind,
                    'status' => DeviceStatus::Active,
                    'revoked_at' => null,
                    'app_version' => $appVersion,
                    'user_agent' => $userAgent,
                    'last_seen_at' => now(),
                    'last_ip' => $actor->ip,
                    'block_size' => $blockSize,
                ], fn ($v) => $v !== null) + ['revoked_at' => null])->save();

                $token = $existing->createToken("device:{$existing->public_id}", ReceptionDevice::ABILITIES, now()->addDays(ReceptionDevice::TOKEN_DAYS));
                $this->audit->record(AuditAction::Update, $existing, null, ['token_rotated' => true, 'name' => $name], ['actor_user_id' => $actor->userId]);
                DeviceRegistered::dispatch($existing, true);

                return ['device' => $existing, 'token' => $token, 'rotated' => true];
            }

            Branch::query()->whereKey($branch->id)->lockForUpdate()->first();   // serialises the per-branch number
            $number = (int) ReceptionDevice::query()->where('branch_id', $branch->id)->max('number') + 1;

            $device = ReceptionDevice::query()->create([
                'branch_id' => $branch->id,
                'number' => $number,
                'name' => $name,
                'kind' => $kind,
                'device_fingerprint' => $fingerprint,
                'app_version' => $appVersion,
                'registered_by_user_id' => $actor->userId,
                'status' => DeviceStatus::Active,
                'block_size' => $blockSize ?? 5,
                'last_seen_at' => now(),
                'last_ip' => $actor->ip,
                'user_agent' => $userAgent,
            ]);

            $token = $device->createToken("device:{$device->public_id}", ReceptionDevice::ABILITIES, now()->addDays(ReceptionDevice::TOKEN_DAYS));
            $this->audit->record(AuditAction::Create, $device, null, ['name' => $name, 'branch_id' => $branch->id, 'number' => $number, 'kind' => $kind->value], ['actor_user_id' => $actor->userId]);
            DeviceRegistered::dispatch($device, false);

            return ['device' => $device, 'token' => $token, 'rotated' => false];
        });
    }
}
