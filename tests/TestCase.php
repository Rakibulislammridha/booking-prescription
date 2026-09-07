<?php

declare(strict_types=1);

namespace Tests;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Tests\Concerns\InteractsWithAudit;
use Tests\Concerns\WithTenants;

/**
 * Feature/Concurrency base (CONVENTIONS §6.2): both tenant schemas are provisioned once per process (committed),
 * then every test runs inside one transaction on pgsql + catalog.
 */
abstract class TestCase extends BaseTestCase
{
    use InteractsWithAudit;
    use RefreshDatabase, WithTenants {
        WithTenants::migrateDatabases insteadof RefreshDatabase;
    }

    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'catalog'];

    protected function setUp(): void
    {
        parent::setUp();

        // Root Blade views (@vite) render without a build: assertInertia() needs the HTML page, not the XHR JSON.
        $this->withoutVite();
    }

    /**
     * Headers for an Inertia XHR: the response is the page JSON (no root Blade view needed) and the asset
     * version matches, so no 409 reload is triggered.
     *
     * @return array<string, string>
     */
    protected function inertiaHeaders(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))];
    }

    /**
     * Relative URIs are built on the HTTP_HOST set by asTenant()/asCentral() (withServerVariables), so
     * $this->get('/panel/...') reaches the tenant host instead of config('app.url').
     */
    protected function prepareUrlForRequest($uri): string
    {
        $host = $this->serverVariables['HTTP_HOST'] ?? null;

        if (is_string($host) && $host !== '' && ! preg_match('#^https?://#i', $uri)) {
            return 'http://'.$host.'/'.ltrim($uri, '/');
        }

        return parent::prepareUrlForRequest($uri);
    }
}
