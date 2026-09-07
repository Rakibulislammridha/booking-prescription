<?php

declare(strict_types=1);

namespace App\Domain\Reception\Sync;

use App\Domain\Reception\Enums\ConflictReason;
use App\Domain\Reception\Enums\OfflineEventStatus;

/** One handler result (OFFLINE §7.1): accepted | conflict | rejected | pending, with the `server_result` document. */
final readonly class ReplayOutcome
{
    /** @param  array<string, mixed>  $serverResult */
    public function __construct(
        public OfflineEventStatus $status,
        public ?ConflictReason $conflictReason,
        public array $serverResult,
        public ?int $sessionInstanceId = null,
        public ?int $serialBlockId = null,
    ) {}

    /** @param  array<string, mixed>  $result */
    public static function accepted(array $result = [], ?int $sessionInstanceId = null, ?int $serialBlockId = null): self
    {
        return new self(OfflineEventStatus::Accepted, null, $result, $sessionInstanceId, $serialBlockId);
    }

    /** @param  array<string, mixed>  $result */
    public static function conflict(ConflictReason $reason, array $result, ?int $sessionInstanceId = null, ?int $serialBlockId = null): self
    {
        return new self(OfflineEventStatus::Conflict, $reason, $result, $sessionInstanceId, $serialBlockId);
    }

    /** Rejections are not conflicts: the code goes in server_result.rejection (SCHEMA §3.3), never retried by the client. */
    public static function rejected(string $rejection, string $message, ?int $sessionInstanceId = null, ?int $serialBlockId = null, mixed $suggestedNext = null): self
    {
        return new self(OfflineEventStatus::Rejected, null, array_filter(['rejection' => $rejection, 'message' => $message, 'suggested_next' => $suggestedNext], fn ($v) => $v !== null), $sessionInstanceId, $serialBlockId);
    }

    public static function pending(string $dependsOn): self
    {
        return new self(OfflineEventStatus::Pending, ConflictReason::DependencyUnresolved, ['depends_on' => $dependsOn]);
    }

    public function isAccepted(): bool
    {
        return $this->status === OfflineEventStatus::Accepted;
    }
}
