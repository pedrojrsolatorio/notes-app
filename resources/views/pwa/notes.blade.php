<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <meta name="theme-color" content="#09090b" />

    <title>{{ __('Notes') }} - {{ config('app.name', 'Laravel') }}</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.webmanifest">

    @fonts

    @vite(['resources/css/app.css', 'resources/js/offline-app.js'])
</head>
<body class="min-h-screen bg-white dark:bg-[#0a0a0a] text-[#1b1b18] dark:text-[#ededec]" data-user-id="{{ auth()->id() }}">
    <div id="offline-app">
        <div class="flex min-h-screen items-center justify-center p-6 text-sm text-zinc-500">
            Loading your notes…
        </div>
    </div>
    <noscript>
        <div class="flex min-h-screen items-center justify-center p-6 text-center text-sm">
            {{ __('This view needs JavaScript to load your local notes. Open Notes in your browser instead.') }}
        </div>
    </noscript>
</body>
</html>
