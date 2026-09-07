<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Serials\Enums\SerialStatus;
use PHPUnit\Framework\TestCase;

/** SERIAL_ENGINE §3.2: which channel draws from which pool — online/kiosk never touch the counter's numbers. */
final class BookingChannelTest extends TestCase
{
    public function test_channel_pools(): void
    {
        $this->assertSame(SerialPool::Online, BookingChannel::Online->pool(true));
        $this->assertSame(SerialPool::Online, BookingChannel::Kiosk->pool(true));
        $this->assertSame(SerialPool::Online, BookingChannel::Telemedicine->pool(false));
        $this->assertSame(SerialPool::Counter, BookingChannel::Counter->pool(true));
        $this->assertSame(SerialPool::Counter, BookingChannel::Phone->pool(true));
        $this->assertSame(SerialPool::Buffer, BookingChannel::Walkin->pool(true));
        $this->assertSame(SerialPool::Counter, BookingChannel::Followup->pool(true));
        $this->assertSame(SerialPool::Online, BookingChannel::Followup->pool(false));
        $this->assertSame(SerialSource::Kiosk, BookingChannel::Kiosk->serialSource());
        $this->assertTrue(BookingChannel::Kiosk->isSelfService());
        $this->assertFalse(BookingChannel::Phone->isSelfService());
    }

    public function test_appointment_status_mirrors_serial_status(): void
    {
        foreach (SerialStatus::cases() as $status) {
            $this->assertSame($status->value === 'booked' ? 'confirmed' : $status->value, AppointmentStatus::fromSerial($status)->value);
        }

        $this->assertFalse(AppointmentStatus::Cancelled->isLive());
        $this->assertTrue(AppointmentStatus::Confirmed->isLive());
    }
}
