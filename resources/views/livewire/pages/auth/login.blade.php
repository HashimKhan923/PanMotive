<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        $user = auth()->user();

        Session::regenerate();

        // Staff go to the console, which is on its own subdomain in production, so no SPA navigation.
        $isStaff = $user->hasAnyRole(['super_admin', 'support_agent', 'finance', 'catalog_manager']);
        $this->redirectIntended(default: $isStaff ? route('admin.dashboard') : route('dashboard', absolute: false), navigate: ! $isStaff);
    }
}; ?>

<div>
    <h1 class="text-2xl font-bold">Welcome back</h1>
    <p class="mt-1 text-sm text-muted">Log in to track orders, manage saved parts, and spend your cashback.</p>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4 mt-4" :status="session('status')" />

    <form wire:submit="login" class="mt-6 space-y-4">
        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="form.email" id="email" type="email" name="email" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('form.email')" />
        </div>

        <!-- Password -->
        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input wire:model="form.password" id="password" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('form.password')" />
        </div>

        <!-- Remember Me -->
        <div class="flex items-center justify-between">
            <label for="remember" class="inline-flex items-center gap-2 text-sm text-muted">
                <input wire:model="form.remember" id="remember" type="checkbox" class="rounded border-line text-accent focus:ring-accent" name="remember">
                {{ __('Remember me') }}
            </label>
            @if (Route::has('password.request'))
                <a class="text-sm font-medium text-fg underline underline-offset-2" href="{{ route('password.request') }}" wire:navigate>
                    {{ __('Forgot password?') }}
                </a>
            @endif
        </div>

        <x-primary-button class="w-full !py-3">
            {{ __('Log in') }}
        </x-primary-button>

        @if (Route::has('register'))
            <p class="text-center text-sm text-muted">
                New to Panmotive?
                <a href="{{ route('register') }}" wire:navigate class="font-medium text-fg underline underline-offset-2">Create an account</a>
            </p>
        @endif
    </form>
</div>
