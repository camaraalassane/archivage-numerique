<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" translate="no" class="notranslate">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="google" content="notranslate">
        <meta name="robots" content="notranslate">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
                <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Favicon & Icônes pour raccourcis bureau -->
        <link rel="icon" href="/logo-dttia.png" type="image/png">
        <link rel="icon" href="/logo-dttia.png" type="image/png" sizes="192x192">
        <link rel="apple-touch-icon" href="/logo-dttia.png">
        <link rel="shortcut icon" href="/logo-dttia.png" type="image/png">
        <meta name="msapplication-TileImage" content="/logo-dttia.png">
        <meta name="msapplication-TileColor" content="#1a237e">
        <link rel="manifest" href="/manifest.json">
        <meta name="theme-color" content="#1a237e">

        @routes
        @vite(['resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased" translate="no">
        @inertia
    </body>
</html>
