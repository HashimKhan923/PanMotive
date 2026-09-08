<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Two-factor authentication</h2>
    </x-slot>

    <div class="mx-auto max-w-xl px-4 py-10">
        <div class="rounded-xl bg-white p-6 shadow-sm">
            @if (session('success'))
                <p class="mb-4 rounded bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</p>
            @endif

            @if (session('recovery_codes'))
                <div class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4">
                    <p class="text-sm font-semibold text-amber-900">Save these recovery codes now</p>
                    <p class="mb-2 text-xs text-amber-800">Each can be used once if you lose your authenticator. They won't be shown again.</p>
                    <ul class="grid grid-cols-2 gap-1 font-mono text-xs">
                        @foreach (session('recovery_codes') as $rc)
                            <li>{{ $rc }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="text-sm text-gray-600">
                Scan this QR code with Google Authenticator, 1Password, or Authy, then enter the 6-digit code to confirm.
            </p>

            <div class="my-5 flex justify-center">{!! $qrImage !!}</div>

            <p class="mb-4 break-all text-center text-xs text-gray-400">Manual key: {{ $secret }}</p>

            <form method="POST" action="{{ route('two-factor.confirm') }}" class="space-y-3">
                @csrf
                <input name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456"
                       class="w-full rounded-lg border-gray-300 text-center tracking-widest">
                @error('code') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <button class="w-full rounded-lg bg-gray-900 px-4 py-2.5 font-semibold text-white hover:bg-gray-700">
                    Confirm &amp; enable
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
