<?php

namespace App\Support;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policies\Policy;
use Spatie\Csp\Scheme;

/**
 * Ships as Content-Security-Policy-Report-Only until CSP_ENFORCE=true
 * (see config/csp.php). Inline <script>/<style> blocks get a per-request nonce
 * automatically — add nonce="{{ csp_nonce() }}" to any inline script/style.
 */
class AppCspPolicy extends Policy
{
    public function configure()
    {
        $this
            ->addDirective(Directive::BASE, Keyword::SELF)
            ->addDirective(Directive::DEFAULT, Keyword::SELF)
            ->addDirective(Directive::OBJECT, Keyword::NONE)
            ->addDirective(Directive::FRAME_ANCESTORS, Keyword::NONE)
            ->addDirective(Directive::FORM_ACTION, Keyword::SELF)

            // Vite bundle is self-hosted; Stripe.js + Chart.js (admin) are the only externals.
            ->addDirective(Directive::SCRIPT, [Keyword::SELF, 'js.stripe.com', 'cdn.jsdelivr.net'])
            ->addNonceForDirective(Directive::SCRIPT)

            ->addDirective(Directive::STYLE, [Keyword::SELF, 'fonts.bunny.net', Keyword::UNSAFE_INLINE])
            ->addDirective(Directive::FONT, [Keyword::SELF, 'fonts.bunny.net', Scheme::DATA])

            // Product images: local storage + data: (fallback/placeholder) + the S3/CDN host.
            ->addDirective(Directive::IMG, array_filter([
                Keyword::SELF, Scheme::DATA, config('filesystems.disks.s3.url') ?: null,
            ]))

            // Livewire XHR + Stripe API from the browser.
            ->addDirective(Directive::CONNECT, [Keyword::SELF, 'api.stripe.com'])

            // Stripe Elements iframes.
            ->addDirective(Directive::FRAME, ['js.stripe.com'])

            ->addDirective(Directive::WORKER, Keyword::SELF)   // /sw.js
            ->addDirective(Directive::MANIFEST, Keyword::SELF); // /manifest.json
    }
}
