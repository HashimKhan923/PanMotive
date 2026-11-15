@extends('layouts.storefront')
@section('title', 'Terms of Service')
@section('meta_description', 'The rules for buying and selling auto parts on Panmotive.')

@php($support = \App\Support\MailSettings::supportEmail())

@section('content')
<div class="container-x max-w-3xl py-12">
    <div data-reveal>
        <h1 class="text-3xl font-bold tracking-tight">Terms of Service</h1>
        <p class="mt-2 text-sm text-muted">Last updated: November 15, 2026</p>
        <p class="mt-4 text-muted">These terms are the rules for using Panmotive. By creating an account, buying or selling, you agree to them. Please also read our <a href="{{ route('privacy') }}" class="text-accent hover:underline">Privacy Policy</a>.</p>
    </div>

    <div class="mt-10 space-y-8 text-sm leading-relaxed" data-reveal-group>
        <section>
            <h2 class="text-lg font-semibold">1. What Panmotive is</h2>
            <p class="mt-3 text-muted">Panmotive is a marketplace. Independent sellers list and sell auto parts; Panmotive provides the website, takes payments, and helps with shipping, returns and disputes. When you buy a part, you are buying it from the seller shown on the listing, not from Panmotive.</p>
        </section>

        <section>
            <h2 class="text-lg font-semibold">2. Your account</h2>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-muted">
                <li>You must be 18 or older and give true, current information.</li>
                <li>Keep your password safe. You are responsible for what happens in your account. Tell us right away if you think someone else used it.</li>
                <li>You can sign in with your email and password or with Google.</li>
                <li>We may suspend or close accounts that break these terms or put others at risk.</li>
            </ul>
        </section>

        <section>
            <h2 class="text-lg font-semibold">3. Buying</h2>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-muted">
                <li>Prices, shipping costs and any taxes are shown before you pay. Your order is a promise to buy; the seller confirms it by shipping.</li>
                <li>We hold your payment and pay the seller after delivery, so you're protected if the part never arrives or isn't as described.</li>
                <li>Check the fitment for your vehicle before buying. If a part listed for your vehicle doesn't fit, the <a href="{{ route('fit-guarantee') }}" class="text-accent hover:underline">Fit Guarantee</a> gives you a full refund and free return shipping.</li>
                <li>Returns follow the seller's return window shown on the listing, plus the Fit Guarantee and our dispute process.</li>
                <li>Have parts installed by someone qualified. Wrong installation can be dangerous, and Panmotive and sellers are not responsible for damage caused by installation.</li>
            </ul>
        </section>

        <section>
            <h2 class="text-lg font-semibold">4. Selling</h2>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-muted">
                <li>Sellers must pass our approval and identity checks and follow the Seller rules shown in the seller portal.</li>
                <li>Listings must be honest: correct condition, photos, price and <strong class="text-fg">vehicle fitment</strong>. Sellers are responsible for the parts they sell and for following the law, including emissions rules (for example California CARB rules).</li>
                <li>Ship on time with tracking, answer buyers, and honor returns, the Fit Guarantee and dispute decisions.</li>
                <li>Panmotive charges the fees shown in the seller portal (such as commission and optional advertising). Fees are taken from sales before payout.</li>
                <li>Sellers are responsible for their own taxes. We send tax forms (such as Form 1099-K) when the law requires.</li>
                <li>Sellers who connect Facebook or Instagram allow Panmotive to post what they send from Social studio. They can disconnect at any time.</li>
            </ul>
        </section>

        <section>
            <h2 class="text-lg font-semibold">5. Not allowed</h2>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-muted">
                <li>Stolen, fake or counterfeit parts, and recalled parts that haven't been fixed.</li>
                <li>Parts made to remove or defeat emissions controls ("defeat devices"), and anything illegal to sell.</li>
                <li>Airbags or other safety parts that are used, damaged or not allowed for resale.</li>
                <li>Fake reviews, misleading listings, or moving a sale off Panmotive to avoid fees.</li>
                <li>Spam, harassment, hacking, scraping the site, or using it to break the law.</li>
            </ul>
        </section>

        <section>
            <h2 class="text-lg font-semibold">6. Reviews and content</h2>
            <p class="mt-3 text-muted">You keep ownership of the reviews, photos and listings you post, but you let Panmotive show and use them on the site and in its marketing. Don't post anything you don't have the right to share. We may remove content that breaks these terms.</p>
        </section>

        <section>
            <h2 class="text-lg font-semibold">7. Problems and disputes</h2>
            <p class="mt-3 text-muted">If something goes wrong with an order, first message the seller from your order page. If you can't sort it out, open a return or dispute and our team will review it. We try to make fair decisions based on the order, messages, photos and tracking.</p>
        </section>

        <section>
            <h2 class="text-lg font-semibold">8. Limits on our responsibility</h2>
            <p class="mt-3 text-muted">Panmotive is provided "as is". We work hard to keep it running and safe, but we can't promise it will always be available or error-free. Sellers, not Panmotive, are responsible for their parts and listings. To the extent the law allows, Panmotive is not liable for indirect or unexpected losses, and our total liability for any claim is limited to the amount you paid in the order the claim is about. Nothing in these terms limits rights you have under consumer protection laws.</p>
        </section>

        <section>
            <h2 class="text-lg font-semibold">9. Changes</h2>
            <p class="mt-3 text-muted">We may update these terms. We'll change the date at the top, and for big changes we'll tell you before they apply. If you keep using Panmotive after that, the new terms apply.</p>
        </section>

        <section>
            <h2 class="text-lg font-semibold">10. Contact us</h2>
            <p class="mt-3 text-muted">Email <a href="mailto:{{ $support }}" class="text-accent hover:underline">{{ $support }}</a>.</p>
        </section>
    </div>
</div>
@endsection
