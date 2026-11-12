import './bootstrap';
import { initMotion, refreshMotion, pageTransition, flyToCart, initTilt, initProductMorph, animateAdded } from './motion';
import { initBanners } from './banners';

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
    const colors = ['#E51B2B', '#15191E', '#CC1726', '#5E6672', '#FFFFFF'];
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
    /* Search box suggestions (components/search-suggest.blade.php). */
    window.Alpine.data('searchSuggest', (endpoint) => ({
        q: '',
        open: false,
        loading: false,
        searched: false,
        active: -1,
        results: { products: [], categories: [], brands: [], vehicle: null },
        recent: [],
        controller: null,
        init() {
            this.q = this.$el.querySelector('input[name=q]')?.value || '';
            try { this.recent = JSON.parse(localStorage.getItem('pm-recent-searches') || '[]').slice(0, 5); } catch (e) { this.recent = []; }
        },
        openPanel() { this.open = true; this.active = -1; if (this.q.trim().length >= 2 && !this.searched) this.fetchSuggestions(); },
        hasContent() { return this.q.trim().length >= 2 || this.recent.length > 0; },
        async fetchSuggestions() {
            const term = this.q.trim();
            this.open = true;
            this.active = -1;
            if (term.length < 2) { this.searched = false; this.results = { products: [], categories: [], brands: [], vehicle: null }; return; }
            this.controller?.abort();
            this.controller = new AbortController();
            this.loading = true;
            try {
                const res = await fetch(endpoint + '?q=' + encodeURIComponent(term), { headers: { Accept: 'application/json' }, signal: this.controller.signal });
                if (res.ok && term === this.q.trim()) { this.results = await res.json(); this.searched = true; }
            } catch (e) { /* aborted or offline: keep the last results */ }
            if (term === this.q.trim()) this.loading = false;
        },
        links() { return [...this.$el.querySelectorAll('[data-suggest-panel] a[role=option]')]; },
        move(step) {
            const items = this.links();
            if (!items.length) return;
            this.open = true;
            this.active = (this.active + step + items.length) % items.length;
            items[this.active]?.scrollIntoView({ block: 'nearest' });
        },
        choose(event) {
            const items = this.links();
            if (this.open && this.active >= 0 && items[this.active]) {
                event.preventDefault();
                this.remember(this.q.trim().length >= 2 ? this.q : items[this.active].textContent.trim());
                window.location.href = items[this.active].href;
            }
        },
        remember(term) {
            term = (term || '').trim();
            if (term.length < 2) return;
            this.recent = [term, ...this.recent.filter((t) => t.toLowerCase() !== term.toLowerCase())].slice(0, 5);
            try { localStorage.setItem('pm-recent-searches', JSON.stringify(this.recent)); } catch (e) {}
        },
        clearRecent() { this.recent = []; try { localStorage.removeItem('pm-recent-searches'); } catch (e) {} },
        searchUrl(term) { return this.$el.action + '?q=' + encodeURIComponent(term); },
        /* Bold the words the shopper typed. Escapes first, so titles can never inject HTML. */
        mark(text) {
            const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            let html = esc(text);
            const words = this.q.trim().split(/\s+/).filter((w) => w.length >= 2).map((w) => esc(w).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
            if (words.length) html = html.replace(new RegExp('(' + words.join('|') + ')', 'gi'), '<mark class="bg-transparent font-bold text-fg">$1</mark>');
            return html;
        },
    }));

    /* Theme (the no-flash class is set in <head>) */
    window.Alpine.store('theme', {
        dark: document.documentElement.classList.contains('dark'),
        toggle() {
            this.dark = !this.dark;
            document.documentElement.classList.toggle('dark', this.dark);
            try { localStorage.setItem('pm-theme', this.dark ? 'dark' : 'light'); } catch (e) {}
            document.cookie = 'pm-theme=' + (this.dark ? 'dark' : 'light') + ';path=/;max-age=31536000;SameSite=Lax';
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
    // A Livewire update rebuilds attributes from the server's HTML, which wipes the inline
    // "opacity: 1" the reveal animation left behind, so revealed sections fell back to the
    // hidden starting style (the cart went blank after removing an item). Keep them shown.
    const keepRevealed = (el) => {
        if (el.nodeType !== 1 || !(el.matches('[data-reveal]') || el.parentElement?.matches('[data-reveal-group]'))) return;
        el.style.opacity = '1';
        if (el.dataset.reveal === 'mask') el.style.clipPath = 'none';
    };
    window.Livewire.hook('morph.updated', ({ el }) => keepRevealed(el));
    window.Livewire.hook('morph.added', ({ el }) => { keepRevealed(el); animateAdded(el); });
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
    initBanners();
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
    initBanners();
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
