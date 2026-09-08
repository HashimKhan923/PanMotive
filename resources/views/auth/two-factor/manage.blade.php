<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">Two-factor authentication</h2>
    </x-slot>

    <div class="mx-auto max-w-xl px-4 py-10">
        <div class="rounded-xl bg-white p-6 shadow-sm">
            @if (session('success'))
                <p class="mb-4 rounded bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</p>
            @endif

            <p class="mb-4 text-sm text-gray-600">
                Two-factor authentication is <span class="font-semibold text-green-700">enabled</span> on this account.
            </p>

            <form method="POST" action="{{ route('two-factor.disable') }}" class="space-y-3">
                @csrf
                @method('DELETE')
                <label class="text-sm font-medium">Confirm your password to disable</label>
                <input type="password" name="password" autocomplete="current-password"
                       class="w-full rounded-lg border-gray-300">
                @error('password') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <button class="rounded-lg bg-red-600 px-4 py-2.5 font-semibold text-white hover:bg-red-500">
                    Disable two-factor
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
