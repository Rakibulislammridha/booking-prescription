<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Telemedicine\Enums\RoomStatus;

/** The consultation is over, or was cancelled: a link that worked this morning must not reopen it. */
final class RoomNotJoinable extends DomainException
{
    public function __construct(public readonly RoomStatus $status)
    {
        parent::__construct(match ($status) {
            RoomStatus::Ended => __('telemedicine.errors.room_ended'),
            RoomStatus::Cancelled => __('telemedicine.errors.room_cancelled'),
            RoomStatus::Open => __('telemedicine.errors.room_open'),
            RoomStatus::Scheduled => __('telemedicine.errors.room_scheduled'),
        });
    }

    public function code(): string
    {
        return 'telemedicine.room_not_joinable';
    }

    public function status(): int
    {
        return 409;
    }
}
