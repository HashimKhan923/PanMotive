<x-seller-layout>
    <div class="mx-auto max-w-xl py-16 text-center">
        <span class="grid mx-auto h-12 w-12 place-items-center rounded-2xl bg-surface-2 text-fg">
            <x-ui.icon name="clock" class="w-6 h-6" />
        </span>
        <h1 class="mt-4 text-2xl font-bold">Your application is under review</h1>
        <p class="mt-3 text-sm text-muted">
            We're verifying your business details and payout account. This usually takes 1–2 business days —
            you'll get an email the moment you're approved to list parts.
        </p>
        <a href="{{ route('seller.onboarding.start') }}" class="btn-outline mt-8">Review my details</a>
    </div>
</x-seller-layout>
