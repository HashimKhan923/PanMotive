@extends('layouts.storefront')
@section('title', 'Delete your data')
@section('meta_description', 'How to disconnect Facebook or Instagram from Panmotive and delete the data we keep, or delete your whole Panmotive account.')

@section('content')
<div class="container-x max-w-3xl py-12">
    <div data-reveal>
        <h1 class="text-3xl font-bold tracking-tight">Delete your data</h1>
        <p class="mt-3 text-muted">You can remove what Panmotive keeps about you at any time. Here is how.</p>
    </div>

    <div class="mt-8 space-y-4" data-reveal-group>
        <div class="card p-6">
            <h2 class="font-semibold">Facebook and Instagram (sellers)</h2>
            <p class="mt-2 text-sm text-muted">When a seller connects Facebook or Instagram, Panmotive keeps only the Page or account name, its ID and the access key Facebook gives us, so we can post what the seller sends from Social studio. We do not read your messages, friends or personal profile.</p>
            <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm">
                <li>Sign in to your seller account and open <strong>Marketing → Social studio → Connected accounts</strong>.</li>
                <li>Press <strong>Disconnect</strong> next to Facebook and Instagram. We delete the saved access key and account details straight away.</li>
                <li>You can also remove Panmotive in Facebook: <strong>Settings → Business integrations</strong> (or Apps and websites) → Panmotive → <strong>Remove</strong>.</li>
            </ol>
        </div>

        <div class="card p-6">
            <h2 class="font-semibold">Your whole Panmotive account</h2>
            <p class="mt-2 text-sm text-muted">Email <a href="mailto:{{ \App\Support\MailSettings::supportEmail() }}" class="text-accent hover:underline">{{ \App\Support\MailSettings::supportEmail() }}</a> from the email address on your account and ask us to delete it. We will confirm within 30 days. Order and tax records the law asks us to keep are kept for that time only.</p>
        </div>
    </div>
</div>
@endsection
