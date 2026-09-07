<?php

declare(strict_types=1);

namespace App\Domain\Serials\Events;

/** Session-level event (SERIAL_ENGINE §15); `values` carries the action's numbers (delay_minutes, by, block_id, …). */
final class DoctorArrived extends SessionEvent {}
