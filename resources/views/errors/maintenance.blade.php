<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Back soon · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="grid min-h-screen place-items-center bg-bg p-6 text-center text-fg">
    <div class="max-w-md">
        <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-accent/10 text-accent"><x-ui.icon name="wrench" class="w-7 h-7" /></span>
        <h1 class="mt-5 text-2xl font-bold">We'll be right back</h1>
        <p class="mt-2 text-sm text-muted">{{ $message ?: "We're making some improvements to Pan Motive. Your cart and orders are safe." }}</p>
    </div>
</body>
</html>
