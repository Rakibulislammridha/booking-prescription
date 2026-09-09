<?php

declare(strict_types=1);

/*
 * Caddy on-demand TLS "ask" endpoint (docker/caddy/Caddyfile, docs/DEPLOYMENT.md §6.2).
 *
 * Caddy calls  GET /ask?domain=<hostname>  before issuing a certificate for a name it has never seen. 200 means
 * "issue", anything else means "refuse". Without this gate anyone pointing a hostname at the VPS could make Caddy
 * burn ACME issuances on junk. The rules mirror App\Tenancy\TenantResolver (ARCHITECTURE §4.2) exactly:
 *
 *   1. lower-case, trim, strip a trailing :port; must be a syntactically valid hostname (<= 253 chars)
 *   2. {central}, www.{central}, super.{central}, ws.{central}  → yes (platform hosts)
 *   3. one leading service label (queue. | book. | display.) is stripped — config/tenancy.php `service_prefixes`
 *   4. {slug}.{central} with a single label → public.tenants.slug, not soft-deleted, status != cancelled
 *      (a SUSPENDED tenant still gets a certificate: EnsureTenantIsActive serves its 402 page over TLS)
 *   5. anything else → public.domains.domain with verification_status = 'verified' joined to a live tenant
 *
 * This is INFRASTRUCTURE code, not application code: it never writes, it is not autoloaded by the app, it runs
 * under PHP's built-in server on the internal compose network only (docker/entrypoint.sh `tls-ask`), and it reads
 * the same DB_* environment the app does. The service-prefix list is duplicated here because booting Laravel for
 * one SELECT is not worth it; keep it in step with config/tenancy.php.
 */

const SERVICE_PREFIXES = ['queue', 'book', 'display'];
const HOSTNAME_RE = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, string $body): never
{
    http_response_code($status);
    echo $body, "\n";
    exit;
}

function env(string $key, string $default = ''): string
{
    $value = getenv($key);

    return $value === false || $value === '' ? $default : (string) $value;
}

function pdo(): PDO
{
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
        env('DB_HOST', '127.0.0.1'),
        env('DB_PORT', '5432'),
        env('DB_DATABASE', 'booking'),
        env('DB_SSLMODE', 'prefer'),
    );

    return new PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3,
    ]);
}

function normalise(string $host): string
{
    $host = strtolower(trim($host));

    return (string) preg_replace('/:\d+$/', '', $host);
}

/** Pure decision logic, separated so it can be exercised without a socket: allowed(host, central, lookup) */
function allowed(string $host, string $central, callable $slugExists, callable $domainExists): bool
{
    $host = normalise($host);

    if ($host === '' || $central === '' || preg_match(HOSTNAME_RE, $host) !== 1) {
        return false;
    }

    if (in_array($host, [$central, 'www.'.$central, 'super.'.$central, 'ws.'.$central], true)) {
        return true;
    }

    foreach (SERVICE_PREFIXES as $prefix) {
        if (str_starts_with($host, $prefix.'.')) {
            $host = substr($host, strlen($prefix) + 1);
            break;
        }
    }

    if (str_ends_with($host, '.'.$central)) {
        $slug = substr($host, 0, -strlen('.'.$central));

        if ($slug !== '' && ! str_contains($slug, '.')) {
            return $slugExists($slug);
        }
    }

    return $domainExists($host);
}

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($path === '/health') {
    try {
        pdo()->query('select 1')->fetchColumn();
        respond(200, 'ok');
    } catch (Throwable) {
        respond(503, 'database unavailable');
    }
}

if ($path !== '/ask') {
    respond(404, 'not found');
}

$domain = (string) ($_GET['domain'] ?? '');
$central = normalise(env('APP_CENTRAL_DOMAIN'));

try {
    $db = pdo();

    $slugExists = static function (string $slug) use ($db): bool {
        $statement = $db->prepare(
            "select 1 from public.tenants where slug = :slug and deleted_at is null and status <> 'cancelled' limit 1",
        );
        $statement->execute(['slug' => $slug]);

        return $statement->fetchColumn() !== false;
    };

    $domainExists = static function (string $host) use ($db): bool {
        $statement = $db->prepare(
            "select 1 from public.domains d join public.tenants t on t.id = d.tenant_id
             where d.domain = :host and d.verification_status = 'verified' and t.deleted_at is null and t.status <> 'cancelled'
             limit 1",
        );
        $statement->execute(['host' => $host]);

        return $statement->fetchColumn() !== false;
    };

    if (allowed($domain, $central, $slugExists, $domainExists)) {
        respond(200, 'ok');
    }

    respond(403, 'unknown host');
} catch (Throwable $e) {
    error_log('tls-ask: '.$e->getMessage());
    respond(503, 'lookup failed');
}
