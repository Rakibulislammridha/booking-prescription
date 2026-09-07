<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        // The one application connection. A tenant is "entered" by switching the session search_path
        // to exactly "tenant_<id>" (App\Tenancy\Database\TenantAwarePostgresConnection); central code runs on 'public'.
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'booking'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',          // MUST stay exactly 'public' — ARCHITECTURE §4.1 (dropAllTables reads it)
            'timezone' => 'UTC',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'application_name' => 'bp-'.env('APP_ENV', 'local'),
        ],

        // Runtime catalog connection. The production role has SELECT only.
        'catalog' => [
            'driver' => 'pgsql',
            'host' => env('CATALOG_DB_HOST', '127.0.0.1'),
            'port' => env('CATALOG_DB_PORT', '5432'),
            'database' => env('CATALOG_DB_DATABASE', 'catalog'),
            'username' => env('CATALOG_DB_USERNAME', 'root'),
            'password' => env('CATALOG_DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'timezone' => 'UTC',
            'sslmode' => env('CATALOG_DB_SSLMODE', 'prefer'),
            'application_name' => 'bp-catalog-'.env('APP_ENV', 'local'),
        ],

        // Admin (write) catalog connection: catalog:migrate, catalog:import, brand promotion.
        'catalog_admin' => [
            'driver' => 'pgsql',
            'host' => env('CATALOG_DB_HOST', '127.0.0.1'),
            'port' => env('CATALOG_DB_PORT', '5432'),
            'database' => env('CATALOG_DB_DATABASE', 'catalog'),
            'username' => env('CATALOG_ADMIN_DB_USERNAME', env('CATALOG_DB_USERNAME', 'root')),
            'password' => env('CATALOG_ADMIN_DB_PASSWORD', env('CATALOG_DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'timezone' => 'UTC',
            'sslmode' => env('CATALOG_DB_SSLMODE', 'prefer'),
            'application_name' => 'bp-catalog-admin-'.env('APP_ENV', 'local'),
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', env('REDIS_DB', '1')),
        ],

    ],

];
