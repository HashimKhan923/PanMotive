<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Back soon · {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css'])
</head>
<body class="grid min-h-screen place-items-center bg-bg p-6 text-center text-fg">
    <div class="max-w-md">
        <x-ui.logo variant="stacked" class="mx-auto h-16 w-auto" />
        <h1 class="mt-5 text-2xl font-bold">We'll be right back</h1>
        <p class="mt-2 text-sm text-muted">{{ $message ?: "We're making some improvements to Panmotive. Your cart and orders are safe." }}</p>
    </div>
</body>
</html>
