<x-guest-layout>
    <div class="mb-4 text-sm text-gray-600">
        Enter the 6-digit code from your authenticator app, or one of your recovery codes.
    </div>

    <form method="POST" action="{{ route('two-factor.verify') }}" class="space-y-4">
        @csrf
        <div>
            <x-input-label for="code" :value="__('Authentication code')" />
            <x-text-input id="code" name="code" type="text" inputmode="text" autocomplete="one-time-code"
                          class="mt-1 block w-full tracking-widest" autofocus />
            <x-input-error :messages="$errors->get('code')" class="mt-2" />
        </div>

        <div class="flex justify-end">
            <x-primary-button>{{ __('Verify') }}</x-primary-button>
        </div>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-4">
        @csrf
        <button type="submit" class="text-sm text-gray-500 underline">Sign out</button>
    </form>
</x-guest-layout>
