@extends('layouts.storefront')
@section('title', 'Privacy Policy')
@section('meta_description', 'What information Panmotive collects, why we use it, who we share it with, and the choices you have.')

@php($support = \App\Support\MailSettings::supportEmail())

@section('content')
<div class="container-x max-w-3xl py-12">
    <div data-reveal>
        <h1 class="text-3xl font-bold tracking-tight">Privacy Policy</h1>
        <p class="mt-2 text-sm text-muted">Last updated: November 15, 2026</p>
        <p class="mt-4 text-muted">Panmotive is an online marketplace where independent sellers sell auto parts to shoppers in the United States. This policy explains what information we collect, why, who we share it with, and the choices you have. We wrote it in plain English. Questions? Email <a href="mailto:{{ $support }}" class="text-accent hover:underline">{{ $support }}</a>.</p>
    </div>

    <div class="prose-legal mt-10 space-y-8 text-sm leading-relaxed" data-reveal-group>
        <section>
            <h2 class="text-lg font-semibold">1. What we collect</h2>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-muted">
                <li><strong class="text-fg">Account details:</strong> your name, email address, password (stored scrambled, we can't read it), and phone number if you give it.</li>
                <li><strong class="text-fg">Orders:</strong> what you buy, shipping address, phone number for delivery, order messages, returns and reviews.</li>
                <li><strong class="text-fg">Payments:</strong> card payments are handled by <strong class="text-fg">Stripe</strong>. Panmotive never sees or stores your full card number.</li>
                <li><strong class="text-fg">Your garage:</strong> vehicles you save (year, make, model) so we can show parts that fit.</li>
                <li><strong class="text-fg">Sign in with Google:</strong> if you choose it, Google shares your name, email address and a Google account ID with us. We don't get your Google password or anything else from your Google account.</li>
                <li><strong class="text-fg">Sellers:</strong> business name and address, tax details, bank account for payouts and identity checks (handled by Stripe), and, if a seller connects them, the name and ID of their Facebook Page and Instagram account plus the access key Facebook gives us so we can post what they send from Social studio.</li>
                <li><strong class="text-fg">How you use the site:</strong> pages and parts you view, searches, device and browser type, IP address, and cookies (see section 5).</li>
                <li><strong class="text-fg">Messages:</strong> messages between buyers and sellers, and chats with our support (including our AI helper).</li>
            </ul>
        </section>

        <section>
            <h2 class="text-lg font-semibold">2. How we use it</h2>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-muted">
                <li>To run the marketplace: show parts, take orders and payments, pay sellers, ship parts, handle returns and refunds.</li>
                <li>To show parts that fit your vehicle and to recommend parts you may like.</li>
                <li>To send receipts, shipping updates and account messages.</li>
                <li>To send offers and reminders, only if you have not opted out. Every marketing email has an unsubscribe link.</li>
                <li>To keep Panmotive safe: stop fraud, fake reviews and banned items, and to follow the law (for example tax forms for sellers).</li>
                <li>To improve the site and fix problems.</li>
            </ul>
        </section>

        <section>
            <h2 class="text-lg font-semibold">3. Who we share it with</h2>
            <p class="mt-3 text-muted">We do <strong class="text-fg">not sell</strong> your personal information. We share it only to run Panmotive:</p>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-muted">
                <li><strong class="text-fg">The seller you buy from</strong> gets your name, shipping address, phone number and order details so they can send your part.</li>
                <li><strong class="text-fg">Stripe</strong> for payments, seller payouts and identity checks.</li>
                <li><strong class="text-fg">Shipping companies</strong> (such as USPS, UPS and FedEx, through our label partner EasyPost) get the delivery name, address and phone number.</li>
                <li><strong class="text-fg">Service providers</strong> that host the site, keep it secure and send our emails (such as Cloudflare and Google Workspace), and AI providers (such as OpenAI or Anthropic) that power writing help and our support chat. They may only use the information to provide their service to us.</li>
                <li><strong class="text-fg">Facebook and Instagram (Meta)</strong>, only for sellers who connect them, to publish the posts the seller sends.</li>
                <li><strong class="text-fg">The law:</strong> when we must, for example to answer a valid legal request, or to protect people from fraud or harm.</li>
                <li>If Panmotive is ever sold or merged, your information would move to the new owner under this same policy.</li>
            </ul>
        </section>

        <section>
            <h2 class="text-lg font-semibold">4. How long we keep it</h2>
            <p class="mt-3 text-muted">We keep your account information while your account is open. Order, payment and tax records are kept for as long as the law requires (usually up to 7 years). When we no longer need information, we delete it or remove the parts that identify you.</p>
        </section>

        <section>
            <h2 class="text-lg font-semibold">5. Cookies</h2>
            <p class="mt-3 text-muted">Cookies are small files your browser keeps for a website. We use them to:</p>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-muted">
                <li>keep you signed in, remember your cart and keep checkout secure (needed for the site to work);</li>
                <li>remember which link brought you to Panmotive (for example a seller's social post, an email, an ad or a referral link) for up to 30 days, so the right seller or partner gets credit for a sale.</li>
            </ul>
            <p class="mt-3 text-muted">You can block or delete cookies in your browser settings. If you block the needed ones, signing in and checkout won't work.</p>
        </section>

        <section>
            <h2 class="text-lg font-semibold">6. Your choices and rights</h2>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-muted">
                <li><strong class="text-fg">See or fix</strong> your details in your account at any time.</li>
                <li><strong class="text-fg">Stop marketing emails</strong> with the unsubscribe link in any email or in your account's marketing preferences.</li>
                <li><strong class="text-fg">Delete your account or data:</strong> see <a href="{{ route('data-deletion') }}" class="text-accent hover:underline">Delete your data</a>, or email us.</li>
                <li><strong class="text-fg">California and other US states:</strong> depending on where you live, you may have the right to know what we collect, get a copy, correct it, delete it, and opt out of its sale or use for targeted ads. We don't sell personal information. To use any of these rights, email <a href="mailto:{{ $support }}" class="text-accent hover:underline">{{ $support }}</a>. We won't treat you differently for asking.</li>
            </ul>
        </section>

        <section>
            <h2 class="text-lg font-semibold">7. Security</h2>
            <p class="mt-3 text-muted">We use encrypted connections (HTTPS), store passwords and access keys scrambled, and limit who on our team can see personal information. No system is perfect, so please use a strong password and keep it to yourself.</p>
        </section>

        <section>
            <h2 class="text-lg font-semibold">8. Children</h2>
            <p class="mt-3 text-muted">Panmotive is not for children under 13, and we don't knowingly collect their information. You must be 18 or older to buy or sell.</p>
        </section>

        <section>
            <h2 class="text-lg font-semibold">9. Changes</h2>
            <p class="mt-3 text-muted">If we change this policy, we'll update the date at the top. For big changes, we'll tell you by email or on the site before they apply.</p>
        </section>

        <section>
            <h2 class="text-lg font-semibold">10. Contact us</h2>
            <p class="mt-3 text-muted">Email <a href="mailto:{{ $support }}" class="text-accent hover:underline">{{ $support }}</a> with any privacy question or request.</p>
        </section>
    </div>
</div>
@endsection
