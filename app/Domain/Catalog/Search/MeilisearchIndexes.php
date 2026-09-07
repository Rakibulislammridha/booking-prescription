<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Search;

use App\Models\Central\Tenant;
use App\Models\Tenant\CustomBrand;
use App\Tenancy\Facades\Tenancy;
use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;

/**
 * Creates / configures the per-tenant indexes at provisioning (ARCHITECTURE.md §4.4, §8.6): t{id}_custom_brands with
 * CustomBrandIndexSettings and t{id}_patients with the Patients module's settings when they are configured.
 */
final class MeilisearchIndexes
{
    public function __construct(private readonly Client $client) {}

    public function ensureTenantIndexes(Tenant $tenant): void
    {
        Tenancy::run($tenant, function (): void {
            $this->ensure((new CustomBrand)->searchableAs(), CustomBrandIndexSettings::array());

            $patients = config('scout.meilisearch.index-settings.'.config('scout.prefix').'t'.Tenancy::id().'_patients');

            if (is_array($patients)) {
                $this->ensure(config('scout.prefix').'t'.Tenancy::id().'_patients', $patients);
            }
        });
    }

    /** @param  array<string, mixed>  $settings */
    public function ensure(string $uid, array $settings): void
    {
        try {
            $this->client->getIndex($uid);
        } catch (ApiException $e) {
            if ($e->errorCode !== 'index_not_found') {
                throw $e;
            }

            $task = $this->client->createIndex($uid, ['primaryKey' => 'id']);
            $this->client->waitForTask($task['taskUid'], 60_000, 50);
        }

        $task = $this->client->index($uid)->updateSettings($settings);
        $this->client->waitForTask($task['taskUid'], 60_000, 50);
    }

    public function deleteTenantIndexes(Tenant $tenant): void
    {
        Tenancy::run($tenant, function (): void {
            foreach ([(new CustomBrand)->searchableAs(), config('scout.prefix').'t'.Tenancy::id().'_patients'] as $uid) {
                try {
                    $task = $this->client->deleteIndex($uid);
                    $this->client->waitForTask($task['taskUid'], 60_000, 50);
                } catch (ApiException $e) {
                    if ($e->errorCode !== 'index_not_found') {
                        throw $e;
                    }
                }
            }
        });
    }
}
