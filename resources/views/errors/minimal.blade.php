<!DOCTYPE html>
{{-- Branded layout for Laravel's built-in error pages (404, 403, 419, 429, 500, 503…). They extend
     "errors::minimal" and fill the title / code / message sections. Dark unless the visitor chose light. --}}
<html lang="en" class="{{ request()->cookie('pm-theme') !== 'light' ? 'dark' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css'])
</head>
<body class="grid min-h-screen place-items-center bg-bg p-6 text-center text-fg">
    <main class="max-w-md">
        <a href="{{ url('/') }}" aria-label="Panmotive home" class="inline-block">
            <x-ui.logo variant="stacked" class="mx-auto h-16 w-auto" />
        </a>
        <p class="mt-8 text-6xl font-bold tracking-tight text-accent">@yield('code')</p>
        <h1 class="mt-3 text-2xl font-bold">@yield('message')</h1>
        <p class="mt-2 text-sm text-muted">
            {{ match (trim($__env->yieldContent('code'))) {
                '404' => "The page you're looking for isn't here. It may have moved, or the listing was removed.",
                '419' => 'Your session expired. Go back, refresh the page and try again.',
                '429' => "You're going a little fast. Wait a moment, then try again.",
                '403' => "You don't have access to this page.",
                default => 'Something went wrong on our side. Please try again in a moment.',
            } }}
        </p>
        <div class="mt-7 flex flex-wrap justify-center gap-3">
            <a href="{{ rtrim(config('app.url'), '/') }}/" class="btn-accent">Go to the homepage</a>
            <a href="{{ rtrim(config('app.url'), '/') }}/shop" class="btn-outline">Shop all parts</a>
        </div>
    </main>
</body>
</html>
