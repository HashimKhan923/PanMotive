# Pan Motive — Security & Launch Checklist (Phase 9)

Code that ships in this phase is committed. This file tracks the **process, config,
and legal** steps that can't be generated — they need a human, a deployed
environment, or a signature.

---

## 1. Built in Phase 9 (verify in prod config)

| Item | Where | Prod action |
|---|---|---|
| Mandatory 2FA for seller/admin roles | `RequireTwoFactor` middleware, applied in `routes/seller.php` + `routes/admin.php` | None — active as soon as roles exist |
| Per-login OTP challenge | login Volt component → `two-factor.challenge` | Confirm authenticator + recovery-code flow on staging |
| Named rate limiters | `AppServiceProvider::configureRateLimiters()` | `throttle:checkout` on checkout routes; in-component caps on `SupportChat`, `BulkUpload` |
| CSP | `App\Support\AppCspPolicy`, `config/csp.php` | `CSP_ENABLED=true` on staging → watch `CSP_REPORT_URI` → `CSP_ENFORCE=true` → prod |
| Payout job hardening | `ReleaseSellerPayout`: transfer → cashback → mark-released; `failed()` → AdminAlert | None |
| Backups | `spatie/laravel-backup`, `config/backup.php`, `s3-backups` disk, scheduled in `routes/console.php` | Fill `BACKUP_AWS_*` (separate account/bucket from primary), run a **restore drill** |
| Dependency audit CI | `.github/workflows/security.yml` | Push to GitHub; `composer audit` + `npm audit` both currently clean |
| k6 load script | `tests/load/browse-and-checkout.js` | Run against staging: `k6 run -e BASE_URL=https://staging.panmotive.com ...` |

---

## 2. Deploy-time config (not local)

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `APP_KEY` generated **and backed up in a password manager / KMS** — losing it makes
      `SellerProfile.ein_encrypted`, 2FA secrets, and recovery codes permanently unrecoverable.
- [ ] `SESSION_DOMAIN=.panmotive.com` (subdomain SSO: storefront ⇄ `sell.` ⇄ `admin.`
      share one session). Leave `null` for local `php artisan serve`.
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`
- [ ] Real `STRIPE_KEY` / `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET`, `EASYPOST_API_KEY`,
      `ANTHROPIC_API_KEY`, `MEILISEARCH_KEY`
- [ ] `php artisan webpush:vapid` (on the Linux box — Windows PHP openssl can't gen EC keys)
- [ ] `OCTANE_SERVER=swoole`, `php artisan octane:start --server=swoole --workers=4 --task-workers=6`
- [ ] Deploy pipeline: `config:cache` → `route:cache` → `view:cache` → `event:cache` → `queue:restart`
- [ ] MySQL `max_connections` raised to cover Octane `--workers` × app servers (persistent workers hold connections)
- [ ] Horizon worker counts tuned so `ReleaseSellerPayout` / SEO / Scout queues don't back up during bursts
- [ ] Meilisearch: `scout:sync-index-settings` + `scout:import "App\Models\Product"`
- [ ] Redis: separate DBs (or instances) for cache / session / queue / Horizon
- [ ] TLS everywhere, HSTS, `X-Content-Type-Options: nosniff`, `Referrer-Policy` at the edge/proxy

---

## 3. Money-path re-read (done — findings)

- **`ReleaseSellerPayout` reordered.** Was: transfer → set `payout_released_at` → cashback.
  A cashback failure then retried into the `payout_released_at` guard and **skipped cashback
  forever** while the transfer had already gone out. Now: transfer (Stripe `idempotency_key`
  makes retry a no-op) → cashback + `lifetime_spend` in one DB transaction guarded by
  `cashback_credited_at` → **only then** `payout_released_at`. A `failed()` hook raises an
  `AdminAlert`. Covered by `SecurityTest` + `PaymentsEscrowTest` + `RetentionTest`.
- **`PayoutReversalService`** — reachable only via `DisputeController::reverseSellerPayout`,
  which has `abort_unless(auth()->user()->can('refunds.issue'))`. ✔ (`AdminConsoleTest`).
- **`WalletService::debit`** — balance check + write are inside one `DB::transaction`. ✔
  Load-verify under concurrency in step 2's k6 run.
- **Stripe `idempotency_key`** on the transfer is stable (`payout-order-item-{id}`). ✔

---

## 4. Process / legal (cannot be generated)

- [ ] **Third-party penetration test** before taking real cards.
- [ ] **PCI DSS SAQ-A** attestation — card data never touches our servers (Stripe Elements
      + PaymentIntents), so SAQ-A applies; complete the questionnaire.
- [ ] **Terms of Service**, **Privacy Policy**, **Seller Agreement**, **Acceptable Use** — lawyer-reviewed.
- [ ] **GDPR / CCPA**: data-export and account-deletion flows for buyers and sellers
      (soft-delete already in place; add the export + hard-purge job + a request UI).
- [ ] **Cookie / tracking consent** banner if any analytics/marketing pixels are added.
- [ ] **Incident response runbook**: who is paged, how to rotate `APP_KEY` / Stripe keys /
      DB creds, how to force-logout all sessions, breach-notification timeline.
- [ ] **Secrets rotation policy** + schedule (Stripe restricted keys, API keys, DB, Redis).
- [ ] **Backup DR drill**: actually restore a `backup:run` archive into a scratch env and
      boot the app from it. Time it. Repeat quarterly.
- [ ] **Sanctions / KYC**: confirm the Stripe Connect onboarding + our `kyc_status` gate meet
      marketplace obligations in the states/countries we launch in.
- [ ] **Uptime + error monitoring** wired (Sentry/Flare + a health-check pinger on `/up`).
- [ ] **Fraud thresholds** in `FraudScoringService` tuned against real early-order data.
