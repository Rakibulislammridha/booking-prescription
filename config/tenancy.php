<?php

declare(strict_types=1);

return [

    // The platform domain: tenants live at {slug}.{central_domain}, the super panel at super.{central_domain}.
    'central_domain' => env('APP_CENTRAL_DOMAIN', 'bp.localhost'),

    // Leading service labels stripped before resolving a tenant host (queue.demo.bp.localhost → demo.bp.localhost).
    'service_prefixes' => ['queue', 'book', 'display'],

    // Labels that can never be a tenant slug: central hosts, service prefixes and infrastructure names
    // (App\Domain\Tenancy\Rules\NotReservedSlug, ProvisionTenant).
    'reserved_slugs' => ['super', 'www', 'queue', 'book', 'display', 'api', 'admin', 'app', 'mail', 'static', 'cdn'],

    // Session cookie name per tenant host: {prefix}_{slug}_session (ResolveTenant); central hosts keep session.cookie.
    'session_cookie_prefix' => env('TENANCY_SESSION_COOKIE_PREFIX', 'bp'),

    // host → tenant id cache (Redis key tenancy:host:{host}), seconds.
    'host_cache_ttl' => 300,

    // Directory of tenant-schema migrations, run by tenants:migrate inside Tenancy::run().
    'migrations_path' => 'migrations/tenant',

    // Default plan code for tenants:create / ProvisionTenant.
    'default_plan' => 'starter',
];
