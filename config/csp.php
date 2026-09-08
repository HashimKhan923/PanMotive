<?php

use App\Support\AppCspPolicy;

return [

    /*
     * The enforcing Content-Security-Policy header. Empty by default — the app
     * ships CSP in report-only mode (see below). Set CSP_ENFORCE=true on staging
     * once the report-only run is clean, then promote to production.
     */
    'policy' => env('CSP_ENFORCE', false) ? AppCspPolicy::class : '',

    /*
     * Always sent as Content-Security-Policy-Report-Only — never blocks anything,
     * only reports violations to CSP_REPORT_URI. This is the safe way to validate
     * the policy against real traffic before enforcing it.
     */
    'report_only_policy' => AppCspPolicy::class,

    'report_uri' => env('CSP_REPORT_URI', ''),

    /*
     * Whether the CSP middleware is wired in at all (bootstrap/app.php). Off by
     * default so local dev and the test suite are untouched.
     */
    'enabled' => env('CSP_ENABLED', false),

    'nonce_generator' => Spatie\Csp\Nonce\RandomString::class,

    'nonce_enabled' => env('CSP_NONCE_ENABLED', true),
];
