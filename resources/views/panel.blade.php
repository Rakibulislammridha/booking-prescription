<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f766e">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Desk">
    <meta name="robots" content="noindex">
    <link rel="manifest" href="/panel.webmanifest">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <title inertia>{{ config('app.name') }}</title>
    @viteReactRefresh
    @vite(['resources/css/panel.css', 'resources/js/panel/app.tsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
