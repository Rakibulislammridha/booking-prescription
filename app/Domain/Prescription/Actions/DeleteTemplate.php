<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Shared\Actor;
use App\Models\Tenant\PrescriptionTemplate;

/** Soft delete (SCHEMA §3.4). */
final class DeleteTemplate
{
    public function handle(PrescriptionTemplate $template, Actor $actor): void
    {
        $template->delete();
    }
}
