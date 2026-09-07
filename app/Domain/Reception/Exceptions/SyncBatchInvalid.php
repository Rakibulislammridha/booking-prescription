<?php

declare(strict_types=1);

namespace App\Domain\Reception\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** OFFLINE §7.1: a batch over 200 events, or not ascending by sequence_no, is refused as a whole (422). */
final class SyncBatchInvalid extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(__('reception.errors.sync_batch_invalid', ['reason' => $reason]));
    }

    public function code(): string
    {
        return 'reception.sync_batch_invalid';
    }
}
