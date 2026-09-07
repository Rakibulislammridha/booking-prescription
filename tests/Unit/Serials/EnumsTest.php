<?php

declare(strict_types=1);

namespace Tests\Unit\Serials;

use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Enums\ActorType;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Services\SerialTransition;
use App\Domain\Shared\Actor;
use PHPUnit\Framework\TestCase;

final class EnumsTest extends TestCase
{
    public function test_terminal_and_active_statuses(): void
    {
        $this->assertSame(['completed', 'cancelled', 'postponed'], array_values(array_filter(SerialStatus::values(), fn (string $v) => SerialStatus::from($v)->isTerminal())));
        $this->assertSame(['booked', 'checked_in', 'in_consultation'], SerialStatus::activeValues());
        $this->assertTrue(SessionStatus::Paused->acceptsSerials());
        $this->assertFalse(SessionStatus::Closed->acceptsSerials());
    }

    public function test_state_machine_table_matches_the_spec(): void
    {
        $this->assertTrue(SerialTransition::allows(SerialStatus::Booked, SerialStatus::InConsultation));
        $this->assertTrue(SerialTransition::allows(SerialStatus::NoShow, SerialStatus::Booked));
        $this->assertFalse(SerialTransition::allows(SerialStatus::Completed, SerialStatus::CheckedIn));
        $this->assertFalse(SerialTransition::allows(SerialStatus::Cancelled, SerialStatus::CheckedIn));
        $this->assertTrue(SerialTransition::allows(SerialStatus::Cancelled, SerialStatus::CheckedIn, reinstateAfterCancel: true));
        $this->assertFalse(SerialTransition::allows(SerialStatus::Cancelled, SerialStatus::Booked, reinstateAfterCancel: true));
    }

    public function test_session_level_event_types_match_the_check_constraint(): void
    {
        $sessionLevel = array_map(fn (SerialEventType $t) => $t->value, array_filter(SerialEventType::cases(), fn (SerialEventType $t) => $t->isSessionLevel()));
        $this->assertSame(['number_skipped', 'renormalised', 'capacity_extended', 'online_released', 'split_changed', 'block_leased', 'block_released', 'block_revoked', 'delayed', 'void_local'], array_values($sessionLevel));
    }

    public function test_actor_type_from_actor(): void
    {
        $this->assertSame(ActorType::User, ActorType::fromActor(Actor::user(1)));
        $this->assertSame(ActorType::Device, ActorType::fromActor(new Actor(deviceId: 3, source: 'offline_replay')));
        $this->assertSame(ActorType::Patient, ActorType::fromActor(new Actor(patientId: 3, source: 'web')));
        $this->assertSame(ActorType::System, ActorType::fromActor(Actor::system()));
    }
}
