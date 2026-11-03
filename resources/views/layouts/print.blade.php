<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title', 'Print') · {{ config('app.name') }}</title>
    @vite('resources/css/app.css')
    <style>
        @media print { .no-print { display: none !important; } body { background: #fff; } }
        body { background: #f8fafc; }
    </style>
</head>
<body class="text-slate-900">
    <div class="mx-auto max-w-3xl p-6">
        <div class="no-print mb-4 flex justify-between">
            <a href="{{ url()->previous() }}" class="text-sm text-slate-500 hover:text-slate-900">&larr; Back</a>
            <button onclick="window.print()" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Print</button>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-8">
            @yield('content')
        </div>
    </div>
</body>
</html>
