/* k6 load test — run against a DEPLOYED staging env, never local dev.
 *   k6 run -e BASE_URL=https://staging.panmotive.com tests/load/browse-and-checkout.js
 *
 * Watch (per Phase 9 notes):
 *  - Meilisearch + Redis under concurrent /shop search (first bottleneck at scale)
 *  - ReleaseSellerPayout queue depth during delivery bursts (tune Horizon workers)
 *  - MySQL max_connections vs. Octane --workers (persistent workers hold connections)
 *  - AI endpoints (chatbot, listing assistant) have their own per-call cost + rate
 *    limits — load-test those separately, not in this storefront run.
 */
import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'https://panmotive.com';

export const options = {
    stages: [
        { duration: '2m', target: 100 },  // ramp to 100 concurrent users
        { duration: '5m', target: 500 },  // ramp to 500 — launch spike
        { duration: '3m', target: 500 },  // hold
        { duration: '2m', target: 0 },    // ramp down
    ],
    thresholds: {
        http_req_duration: ['p(95)<800'], // 95% under 800ms
        http_req_failed: ['rate<0.01'],   // < 1% errors
    },
};

const SEARCH_TERMS = ['brake pad', 'oil filter', 'alternator', 'spark plug', 'control arm'];

export default function () {
    const home = http.get(`${BASE_URL}/`);
    check(home, { 'home 200': (r) => r.status === 200 });

    const term = SEARCH_TERMS[Math.floor(Math.random() * SEARCH_TERMS.length)];
    const shop = http.get(`${BASE_URL}/shop?q=${encodeURIComponent(term)}`);
    check(shop, { 'search 200': (r) => r.status === 200 });

    const cart = http.get(`${BASE_URL}/cart`);
    check(cart, { 'cart 200': (r) => r.status === 200 });

    sleep(Math.random() * 2 + 1);
}
