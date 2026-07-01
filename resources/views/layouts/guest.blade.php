<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="flex min-h-screen flex-col items-center justify-center bg-brand-900 px-4 py-10">
            <div class="mb-6 flex items-center gap-3">
                <a href="/" class="flex h-12 w-12 items-center justify-center rounded-xl bg-accent text-lg font-extrabold tracking-tight text-brand-900">
                    PX
                </a>
                <div class="flex flex-col leading-tight">
                    <span class="text-lg font-semibold text-white">Mosconi Tours</span>
                    <span class="text-xs text-white/60">B2B Portal</span>
                </div>
            </div>

            <div class="w-full overflow-hidden bg-white px-6 py-6 shadow-xl sm:max-w-md sm:rounded-xl">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
