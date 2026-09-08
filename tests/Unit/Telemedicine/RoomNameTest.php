<?php

declare(strict_types=1);

namespace Tests\Unit\Telemedicine;

use App\Domain\Telemedicine\Enums\RoomStatus;
use App\Domain\Telemedicine\Enums\SessionEndReason;
use App\Domain\Telemedicine\Enums\TelemedicineProvider;
use App\Domain\Telemedicine\Services\RoomName;
use PHPUnit\Framework\TestCase;

/** The room name is the only identifier a patient's SMS carries, so its shape is part of the security story. */
final class RoomNameTest extends TestCase
{
    public function test_the_pattern_accepts_a_real_room_name_and_rejects_a_probe(): void
    {
        $this->assertTrue(RoomName::looksValid('t9001-01jabcdefghjkmnpqrstvwxyz0'));
        $this->assertTrue(RoomName::looksValid('t7-01jabcdefghjkmnpqrstvwxyz0'));

        foreach (['', '1', 't9001', 't9001-', 'x9001-01jabcdefghjkmnpqrstvwxyz0', 't9001-01JABCDEFGHJKMNPQRSTVWXYZ0', 't9001-short', '../../etc/passwd', 't9001-01jabcdefghjkmnpqrstvwxy'] as $bad) {
            $this->assertFalse(RoomName::looksValid($bad), $bad);
        }
    }

    public function test_the_enums_mirror_the_schema_check_lists(): void
    {
        $this->assertSame(['agora', 'livekit', 'jitsi'], TelemedicineProvider::values());
        $this->assertSame(['scheduled', 'open', 'ended', 'cancelled'], RoomStatus::values());
        $this->assertSame(['completed', 'dropped', 'no_show', 'cancelled'], SessionEndReason::values());
    }

    public function test_only_a_completed_call_completes_the_serial(): void
    {
        $this->assertTrue(SessionEndReason::Completed->completesTheSerial());
        $this->assertFalse(SessionEndReason::Dropped->completesTheSerial());
        $this->assertFalse(SessionEndReason::NoShow->completesTheSerial());
        $this->assertFalse(SessionEndReason::Cancelled->completesTheSerial());
    }

    public function test_a_terminal_room_is_not_joinable(): void
    {
        $this->assertTrue(RoomStatus::Scheduled->isJoinable());
        $this->assertTrue(RoomStatus::Open->isJoinable());
        $this->assertTrue(RoomStatus::Ended->isTerminal());
        $this->assertTrue(RoomStatus::Cancelled->isTerminal());
        $this->assertFalse(RoomStatus::Ended->isJoinable());
        $this->assertFalse(RoomStatus::Cancelled->isJoinable());
    }
}
