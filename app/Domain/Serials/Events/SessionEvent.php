<?php

declare(strict_types=1);

namespace App\Domain\Serials\Events;

use App\Domain\Shared\Actor;
use App\Models\Tenant\SessionInstance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Base of the session-level events (SessionDelayed, SessionClosed, …): session ids + actor + a frozen snapshot. */
abstract class SessionEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public readonly int $sessionInstanceId;

    public readonly string $sessionPublicId;

    /** @var array<string, mixed> */
    public readonly array $session;

    /** @param  array<string, mixed>  $values */
    public function __construct(SessionInstance $session, public readonly Actor $actor, public readonly array $values = [])
    {
        $this->sessionInstanceId = $session->id;
        $this->sessionPublicId = $session->public_id;
        $this->session = [
            'id' => $session->id,
            'public_id' => $session->public_id,
            'branch_id' => $session->branch_id,
            'doctor_id' => $session->doctor_id,
            'session_date' => $session->session_date->toDateString(),
            'session_code' => $session->session_code,
            'status' => $session->status->value,
            'delay_minutes' => $session->delay_minutes,
            'max_serials' => $session->max_serials,
            'version' => $session->version,
        ];
    }
}
