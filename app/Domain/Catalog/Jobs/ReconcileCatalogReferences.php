<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Jobs;

use App\Domain\Catalog\Reconcile\ReferenceScanner;
use App\Tenancy\Queue\Middleware\InitializeTenancyForJob;
use App\Tenancy\Queue\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * One tenant's nightly soft-reference scan (CATALOG.md §6, ARCHITECTURE.md §8.3). Queue `default`, TenantAware,
 * WithoutOverlapping per tenant; idempotent — every run writes its own run_id rows.
 */
final class ReconcileCatalogReferences implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    public int $tries = 2;

    public int $backoff = 300;

    public function __construct(public readonly string $runId)
    {
        $this->onQueue('default');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new InitializeTenancyForJob, (new WithoutOverlapping('catalog-reconcile:'.($this->tenantId ?? 'central')))->expireAfter(3600)];
    }

    public function handle(ReferenceScanner $scanner): void
    {
        $scanner->scanCurrentTenant($this->runId);
    }
}
