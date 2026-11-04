<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title', 'Print') · {{ config('app.name') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @vite('resources/css/app.css')
    <style>
        @media print { .no-print { display: none !important; } body { background: #fff; } }
        body { background: #F5F6F8; font-variant-numeric: tabular-nums; }
    </style>
</head>
<body class="text-[#15191E]">
    <div class="mx-auto max-w-3xl p-6">
        <div class="no-print mb-4 flex justify-between">
            <a href="{{ url()->previous() }}" class="text-sm text-[#5E6672] hover:text-[#15191E]">&larr; Back</a>
            <button onclick="window.print()" class="rounded-lg bg-[#15191E] px-4 py-2 text-sm font-semibold text-white">Print</button>
        </div>
        <div class="rounded-2xl border border-[#D9DDE3] bg-white p-8">
            <x-ui.logo class="mb-6 h-5 w-auto" />
            @yield('content')
        </div>
    </div>
</body>
</html>
