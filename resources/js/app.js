import './bootstrap';
import { initMotion, refreshMotion, pageTransition, flyToCart, initTilt, initProductMorph, animateAdded } from './motion';

/* --- Rich text editor (Trix) — loaded only on pages that actually use it --- */
function loadRichEditorIfNeeded() {
    if (document.querySelector('trix-editor') && !window.Trix) {
        import('trix');
        import('trix/dist/trix.css');
    }
}

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

function postJson(url, body) {
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    }).then((r) => (r.ok ? r.json() : Promise.reject(r)));
}

function toast(message, kind = 'info') {
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, kind } }));
}

/* --- One-shot confetti burst --- */
function celebrate() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const host = document.createElement('div');
    host.className = 'pm-confetti';
    const colors = ['#DD1E25', '#17181D', '#F45B60', '#A1A4AD', '#FFFFFF'];
    for (let i = 0; i < 90; i++) {
        const bit = document.createElement('i');
        bit.style.left = Math.random() * 100 + 'vw';
        bit.style.background = colors[i % colors.length];
        bit.style.animationDuration = 1.6 + Math.random() * 1.4 + 's';
        bit.style.animationDelay = Math.random() * 0.25 + 's';
        bit.style.transform = `translateY(0) rotate(${Math.random() * 360}deg)`;
        host.appendChild(bit);
    }
    document.body.appendChild(host);
    setTimeout(() => host.remove(), 3600);
}
window.pmCelebrate = celebrate;
window.addEventListener('celebrate', celebrate);

/* --- Alpine stores --- */
window.addEventListener('alpine:init', () => {
    /* Theme (the no-flash class is set in <head>) */
    window.Alpine.store('theme', {
        dark: document.documentElement.classList.contains('dark'),
        toggle() {
            this.dark = !this.dark;
            document.documentElement.classList.toggle('dark', this.dark);
            try { localStorage.setItem('pm-theme', this.dark ? 'dark' : 'light'); } catch (e) {}
        },
    });

    /* Cart badge — kept in sync by Livewire 'cart-updated' events. */
    window.Alpine.store('cart', {
        count: Number(document.documentElement.dataset.cartCount || 0),
        set(n) { this.count = Number(n) || 0; },
        bump() { this.count += 1; },
    });

    /* Wishlist — ids hydrated once per page, toggled optimistically. */
    window.Alpine.store('wishlist', {
        ids: new Set(),
        count: 0,
        ready: false,
        hydrate() {
            fetch('/wishlist/ids', { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((d) => { this.ids = new Set((d.ids || []).map(Number)); this.count = d.count || 0; this.ready = true; })
                .catch(() => { this.ready = true; });
        },
        has(id) { return this.ids.has(Number(id)); },
        setCount(n) { this.count = Number(n) || 0; },
        toggle(id) {
            id = Number(id);
            const add = !this.ids.has(id);
            if (add) { this.ids.add(id); this.count++; } else { this.ids.delete(id); this.count = Math.max(0, this.count - 1); }
            postJson('/wishlist/toggle', { product_id: id })
                .then((d) => {
                    if (d.in_list) this.ids.add(id); else this.ids.delete(id);
                    this.count = d.count;
                    toast(d.in_list ? 'Saved for later' : 'Removed from saved');
                })
                .catch(() => {
                    if (add) { this.ids.delete(id); this.count = Math.max(0, this.count - 1); } else { this.ids.add(id); this.count++; }
                    toast('Could not update your list', 'error');
                });
        },
    });

    /* Compare — session-backed, max 4. */
    window.Alpine.store('compare', {
        ids: new Set(),
        count: 0,
        max: 4,
        get full() { return this.count >= this.max; },
        hydrate() {
            try {
                const seed = JSON.parse(document.getElementById('compare-seed')?.textContent || '{}');
                this.ids = new Set((seed.ids || []).map(Number));
                this.count = seed.count || this.ids.size;
                this.max = seed.max || 4;
            } catch (e) {}
        },
        has(id) { return this.ids.has(Number(id)); },
        toggle(id) {
            id = Number(id);
            if (!this.ids.has(id) && this.full) {
                toast(`Compare holds up to ${this.max} parts`, 'error');
                return;
            }
            const add = !this.ids.has(id);
            if (add) { this.ids.add(id); this.count++; } else { this.ids.delete(id); this.count = Math.max(0, this.count - 1); }
            postJson('/compare/toggle', { product_id: id })
                .then((d) => {
                    if (d.in_list) this.ids.add(id); else this.ids.delete(id);
                    this.count = d.count;
                    if (d.rejected) toast(`Compare holds up to ${d.max} parts`, 'error');
                })
                .catch(() => {
                    if (add) { this.ids.delete(id); this.count = Math.max(0, this.count - 1); } else { this.ids.add(id); this.count++; }
                });
        },
    });
});

window.flyToCart = flyToCart;

/* Quick add from a product card — no page load. Resolves on success, rejects (with a toast) on failure. */
window.pmQuickAdd = (productId, cardEl) => {
    flyToCart(cardEl);
    return postJson('/cart/add', { product_id: productId, quantity: 1 })
        .then((d) => {
            window.Alpine?.store('cart')?.set(d.count);
            setTimeout(() => window.dispatchEvent(new Event('mini-cart:show')), 620);
            return d;
        })
        .catch(async (r) => {
            let message = 'Could not add to cart';
            try { message = (await r.json()).message || message; } catch (e) {}
            toast(message, 'error');
            throw r;
        });
};

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('morph.added', ({ el }) => animateAdded(el));
    window.Livewire.on('cart-updated', (payload) => {
        const n = Array.isArray(payload) ? payload[0]?.count : payload?.count;
        const store = window.Alpine?.store('cart');
        const prev = store?.count ?? 0;
        store?.set(n);
        if (Number(n) > Number(prev)) {
            // Let the fly-to-cart arc land, then peek the mini-cart.
            setTimeout(() => window.dispatchEvent(new Event('mini-cart:show')), 620);
        }
    });
    window.Livewire.on('wishlist-updated', (payload) => {
        const n = Array.isArray(payload) ? payload[0]?.count : payload?.count;
        window.Alpine?.store('wishlist')?.setCount(n);
    });
    window.Livewire.on('compare-updated', () => {
        window.Alpine?.store('compare')?.hydrate();
    });
    window.Livewire.on('celebrate', () => celebrate());
});

function boot() {
    initMotion();
    initTilt();
    initProductMorph();
    loadRichEditorIfNeeded();
    window.Alpine?.store('wishlist')?.hydrate();
    window.Alpine?.store('compare')?.hydrate();
}
document.addEventListener('DOMContentLoaded', boot);
window.addEventListener('load', refreshMotion);
document.addEventListener('livewire:navigated', () => {
    initMotion();
    pageTransition();
    loadRichEditorIfNeeded();
    window.Alpine?.store('wishlist')?.hydrate();
    window.Alpine?.store('compare')?.hydrate();
});

/* --- PWA service worker --- */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
