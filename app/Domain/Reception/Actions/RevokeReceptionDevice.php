<?php

declare(strict_types=1);

namespace App\Domain\Reception\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Reception\Enums\DeviceStatus;
use App\Domain\Reception\Events\DeviceRevoked;
use App\Domain\Serials\Actions\RevokeBlock;
use App\Domain\Serials\Enums\BlockStatus;
use App\Domain\Shared\Actor;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\SerialBlock;
use Illuminate\Support\Facades\DB;

/** OFFLINE §2.2 / §4.5: device lost, stolen or replaced — delete its tokens and revoke every active block. Idempotent. */
final class RevokeReceptionDevice
{
    public function __construct(private readonly RevokeBlock $revokeBlock, private readonly AuditRecorder $audit) {}

    public function handle(ReceptionDevice $device, Actor $actor, ?string $reason = null): ReceptionDevice
    {
        return DB::transaction(function () use ($device, $actor, $reason): ReceptionDevice {
            /** @var ReceptionDevice $locked */
            $locked = ReceptionDevice::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $locked->tokens()->delete();

            $revoked = 0;

            foreach (SerialBlock::query()->where('reception_device_id', $locked->id)->where('status', BlockStatus::Active->value)->orderBy('range_start')->get() as $block) {
                $this->revokeBlock->handle($block, $actor, $reason ?? 'device_revoked');
                $revoked++;
            }

            if ($locked->status !== DeviceStatus::Revoked) {
                $locked->forceFill(['status' => DeviceStatus::Revoked, 'revoked_at' => now()])->save();
                $this->audit->record(AuditAction::Void, $locked, ['status' => DeviceStatus::Active->value], ['status' => DeviceStatus::Revoked->value, 'reason' => $reason, 'blocks_revoked' => $revoked], ['actor_user_id' => $actor->userId]);
            }

            DeviceRevoked::dispatch($locked, $revoked);

            return $locked;
        });
    }
}
