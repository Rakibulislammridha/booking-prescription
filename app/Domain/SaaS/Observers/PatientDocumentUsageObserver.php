<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Observers;

use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Observers\Concerns\MetersTenantUsage;
use App\Models\Tenant\PatientDocument;

/**
 * `storage_bytes` is a gauge in bytes over the patient documents a clinic has uploaded. The reservation happens
 * before the row exists — i.e. after the file has been accepted but before it is recorded — so a plan's storage
 * cap refuses the upload that would cross it rather than discovering it afterwards.
 */
final class PatientDocumentUsageObserver
{
    use MetersTenantUsage;

    public function creating(PatientDocument $document): void
    {
        $this->reserve(UsageMetric::StorageBytes, max(0, (int) $document->size_bytes));
    }

    public function deleted(PatientDocument $document): void
    {
        $this->release(UsageMetric::StorageBytes, max(0, (int) $document->getOriginal('size_bytes')));
    }
}
