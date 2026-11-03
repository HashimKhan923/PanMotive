<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-bold">{{ __('Profile') }}</h1>
        <p class="mt-1 text-sm text-muted">Manage how you sign in and how we reach you.</p>
    </x-slot>

    <div class="container-x max-w-3xl space-y-6 py-10">
        <div class="card p-6 sm:p-8" data-reveal>
            <div class="max-w-xl">
                <livewire:profile.update-profile-information-form />
            </div>
        </div>

        <div class="card p-6 sm:p-8" data-reveal>
            <div class="max-w-xl">
                <livewire:profile.update-password-form />
            </div>
        </div>

        <div class="card p-6 sm:p-8" data-reveal>
            <div class="max-w-xl">
                <livewire:profile.delete-user-form />
            </div>
        </div>
    </div>
</x-app-layout>
