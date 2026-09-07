@php
    $tenant = $page['props']['tenant'] ?? null;
    $theme = $tenant['theme'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ $theme['primary'] ?? '#0f766e' }}">
    <link rel="icon" href="/favicon.ico" sizes="any">
    @if(!empty($tenant['logo_url']))
    <link rel="icon" href="{{ $tenant['logo_url'] }}">
    @endif
    <title inertia>{{ $tenant['name'] ?? config('app.name') }}</title>
    {{-- Per-tenant theme: --tenant-<key> custom properties consumed by resources/css/site.css @theme (ARCHITECTURE §7.3) --}}
    <style>:root{ @foreach($theme as $k => $v) --tenant-{{ preg_replace('/[^a-z0-9_-]/i', '', $k) }}: {{ $v }}; @endforeach }</style>
    @viteReactRefresh
    @vite(['resources/css/site.css', 'resources/js/site/app.tsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
