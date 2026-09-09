<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Clinic\Support\SettingsRegistry;
use App\Domain\Shared\Actor;
use App\Models\Tenant\User;

/**
 * Write one settings key and leave a trail (BRIEF §5.N: who changed what, when).
 *
 * The audit row for a `secret` key carries the literal `[redacted]`, never the value and never the ciphertext:
 * an audit log is read by more people than a credential store, is kept for longer, and is exported. Recording
 * that a credential CHANGED is the whole point; recording what it changed to would just be a second copy of it
 * in a less protected place. Same rule as ARCHITECTURE §8.1's `"[encrypted]"` for ENC columns.
 */
final class UpdateSetting
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(string $key, mixed $value, Actor $actor): mixed
    {
        $by = $actor->userId !== null ? User::query()->find($actor->userId) : null;
        $secret = SettingsRegistry::isSecret($key);
        $before = $this->settings->get($key);

        $setting = $this->settings->set($key, $value, $by);
        $after = $this->settings->get($key);

        // `null` means nothing was written — a blank submit on a secret, which is "keep what you have" and is not
        // a change to record. Otherwise a value that did not move is not a change either.
        if ($setting !== null && $before !== $after) {
            $this->audit->record(
                AuditAction::Update,
                $setting,
                ['value' => $secret ? '[redacted]' : $before],
                ['value' => $secret ? '[redacted]' : $after],
                ['key' => $key],
            );
        }

        // Never hand a credential back out of the write path: the screen gets the same mask it renders.
        return $secret ? Settings::mask($after) : $after;
    }
}
