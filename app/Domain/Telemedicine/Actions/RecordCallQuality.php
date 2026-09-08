<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Actions;

use App\Models\Tenant\TelemedicineRoom;
use App\Models\Tenant\TelemedicineSession;

/**
 * `telemedicine_sessions.quality` (SCHEMA §3.8): the client's own WebRTC stats, kept because "the call was bad"
 * is otherwise unanswerable a week later. Values are clamped, never trusted: this endpoint is reachable by a
 * patient's browser.
 */
final class RecordCallQuality
{
    /** @param  array<string, mixed>  $stats */
    public function handle(TelemedicineRoom $room, array $stats): ?TelemedicineSession
    {
        $session = TelemedicineSession::query()
            ->where('telemedicine_room_id', $room->id)
            ->orderByDesc('id')
            ->first();

        if ($session === null) {
            return null;
        }

        $existing = $session->quality ?? [];
        $bitrate = isset($stats['avg_bitrate_kbps']) && is_numeric($stats['avg_bitrate_kbps']) ? max(0, min(100000, (int) $stats['avg_bitrate_kbps'])) : null;
        $loss = isset($stats['packet_loss_pct']) && is_numeric($stats['packet_loss_pct']) ? max(0.0, min(100.0, round((float) $stats['packet_loss_pct'], 2))) : null;
        $rtt = isset($stats['rtt_ms']) && is_numeric($stats['rtt_ms']) ? max(0, min(60000, (int) $stats['rtt_ms'])) : null;

        $session->forceFill(['quality' => array_filter([
            'avg_bitrate_kbps' => $bitrate ?? ($existing['avg_bitrate_kbps'] ?? null),
            'packet_loss_pct' => $loss ?? ($existing['packet_loss_pct'] ?? null),
            'rtt_ms' => $rtt ?? ($existing['rtt_ms'] ?? null),
            'samples' => (int) ($existing['samples'] ?? 0) + 1,
        ], fn ($v) => $v !== null)])->save();

        return $session;
    }
}
