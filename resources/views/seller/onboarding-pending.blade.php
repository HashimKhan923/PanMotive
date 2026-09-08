<x-seller-layout>
    <x-slot name="header">Application received</x-slot>

    <div class="mx-auto max-w-xl py-16 text-center" data-reveal>
        <h1 class="text-2xl font-semibold">Your seller application is under review</h1>
        <p class="mt-3 text-slate-600">
            We're verifying your business details and payout account. This usually takes 1–2 business days.
            You'll get an email as soon as you're approved to list parts.
        </p>

        <a href="{{ route('seller.onboarding.start') }}"
           class="mt-8 inline-block rounded-lg border border-slate-300 px-5 py-2.5 text-sm hover:bg-slate-50">
            Review my details
        </a>
    </div>
</x-seller-layout>
