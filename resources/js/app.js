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
    /* Seller email designer (livewire/seller/campaign-designer.blade.php). The server re-checks
       everything on save (CampaignDesign::sanitize), so this only has to be pleasant to use. */
    window.Alpine.data('pmDesigner', (init) => ({
        ...init,
        tab: 'blocks', device: 'desktop', selected: null, dragType: null, dragId: null, dropAt: null,
        past: [], future: [], saving: false, timer: null, search: '', aiGoal: 'new_arrivals', aiBusy: false, full: false, frameKey: 0,
        snapshot() { return JSON.stringify({ blocks: this.blocks, brand: this.brand }); },
        init() { this.last = this.snapshot(); },
        uid() { return 'b' + Math.random().toString(36).slice(2, 10); },
        current() { return this.blocks.find((b) => b.id === this.selected) || null; },
        commit() {
            const now = this.snapshot();
            if (now === this.last) return;
            this.past.push(this.last); if (this.past.length > 60) this.past.shift();
            this.future = []; this.last = now; this.persist();
        },
        persist() {
            clearTimeout(this.timer); this.saving = true;
            this.timer = setTimeout(async () => {
                await this.$wire.save(JSON.parse(JSON.stringify(this.blocks)), JSON.parse(JSON.stringify(this.brand)));
                this.saving = false;
            }, 900);
        },
        restore(state) { const s = JSON.parse(state); this.blocks = s.blocks; this.brand = s.brand; this.last = state; this.persist(); },
        undo() { if (!this.past.length) return; this.future.push(this.snapshot()); this.restore(this.past.pop()); },
        redo() { if (!this.future.length) return; this.past.push(this.snapshot()); this.restore(this.future.pop()); },
        make(type) { return { id: this.uid(), type, props: JSON.parse(JSON.stringify(this.defaults[type])) }; },
        add(type, at = null) {
            const b = this.make(type);
            const idx = at ?? (this.selected ? this.blocks.findIndex((x) => x.id === this.selected) + 1 : this.blocks.length);
            this.blocks.splice(idx, 0, b); this.selected = b.id; this.commit();
        },
        drop(at) {
            if (this.dragType) { this.add(this.dragType, at); }
            else if (this.dragId) {
                const from = this.blocks.findIndex((b) => b.id === this.dragId);
                if (from > -1) { const [b] = this.blocks.splice(from, 1); this.blocks.splice(at > from ? at - 1 : at, 0, b); this.commit(); }
            }
            this.dragType = null; this.dragId = null; this.dropAt = null;
        },
        move(i, dir) { const j = i + dir; if (j < 0 || j >= this.blocks.length) return; [this.blocks[i], this.blocks[j]] = [this.blocks[j], this.blocks[i]]; this.commit(); },
        duplicate(i) { const b = JSON.parse(JSON.stringify(this.blocks[i])); b.id = this.uid(); this.blocks.splice(i + 1, 0, b); this.selected = b.id; this.commit(); },
        remove(i) { if (this.blocks[i].id === this.selected) this.selected = null; this.blocks.splice(i, 1); this.commit(); },
        setImage(id, url) { const b = this.blocks.find((x) => x.id === id); if (b) { b.props.url = url; this.commit(); } },
        found() { const q = this.search.toLowerCase(); return this.catalog.filter((c) => !q || c.title.toLowerCase().includes(q)).slice(0, 40); },
        toggleProduct(id) {
            const p = this.current().props; const i = p.product_ids.indexOf(id);
            i > -1 ? p.product_ids.splice(i, 1) : (p.product_ids.length < 9 && p.product_ids.push(id)); this.commit();
        },
        async ai() {
            this.aiBusy = true;
            try {
                const out = await this.$wire.writeText(this.aiGoal);
                const b = this.current(); b.props.text = b.type === 'heading' ? out.heading : out.text; this.commit();
            } finally { this.aiBusy = false; }
        },
        emoji(t) { return { heading: 'H', text: '¶', image: '🖼', button: '▭', product: '📦', products: '▦', smart_products: '✨', coupon: '🏷', columns: '▥', video: '▶', social: '@', divider: '—', spacer: '↕' }[t] || '■'; },
        esc(s) { return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); },
        fill(s) { return this.esc(s).replaceAll('{first_name}', 'Alex').replaceAll('{store_name}', this.esc(this.store)).replaceAll('{vehicle}', '2018 Toyota Camry').replace(/\n/g, '<br>'); },
        btn(label, align = 'center') {
            const r = { rounded: '8px', pill: '999px', square: '0' }[this.brand.button];
            return `<div style="text-align:${align}"><span style="display:inline-block;background:${this.brand.color};color:#fff;border-radius:${r};padding:11px 24px;font-weight:600;font-size:14px">${this.esc(label || 'Shop now')}</span></div>`;
        },
        card(c, label) {
            if (!c) return '<div style="border:1px dashed #D9DDE3;border-radius:8px;padding:18px;text-align:center;color:#8A919C;font-size:13px">Pick a product</div>';
            const img = c.image ? `<img src="${this.esc(c.image)}" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:8px">` : '<div style="aspect-ratio:1;background:#F5F6F8;border-radius:8px"></div>';
            return `<div style="text-align:center">${img}<p style="margin:8px 0 2px;font-size:13px;font-weight:600;line-height:1.3">${this.esc(c.title)}</p><p style="margin:0 0 8px;font-weight:700">${c.price}</p>${this.btn(label)}</div>`;
        },
        grid(items, cols, label) {
            return `<div style="display:grid;grid-template-columns:repeat(${this.device === 'phone' ? 1 : cols},minmax(0,1fr));gap:12px">${items.map((c) => this.card(c, label)).join('')}</div>`;
        },
        preview(b) {
            const p = b.props; const find = (id) => this.catalog.find((c) => c.id === id);
            switch (b.type) {
                case 'heading': return `<h2 style="margin:0;font-size:${p.size === 'medium' ? 20 : 26}px;font-weight:700;line-height:1.25;text-align:${p.align}">${this.fill(p.text)}</h2>`;
                case 'text': return `<p style="margin:0;font-size:15px;line-height:1.6;color:#3D444E;text-align:${p.align}">${this.fill(p.text)}</p>`;
                case 'image': return p.url ? `<img src="${this.esc(p.url)}" alt="" style="width:100%;border-radius:10px;display:block">` : '<div style="border:2px dashed #D9DDE3;border-radius:10px;padding:34px;text-align:center;color:#8A919C;font-size:13px">Upload or choose an image →</div>';
                case 'button': return this.btn(p.label, p.align);
                case 'product': {
                    const c = find(p.product_id);
                    if (!c) return this.card(null);
                    return `<div style="display:flex;gap:14px;align-items:center;border:1px solid #E6E9ED;border-radius:12px;padding:14px;${this.device === 'phone' ? 'flex-direction:column' : ''}">${c.image ? `<img src="${this.esc(c.image)}" style="width:${this.device === 'phone' ? '100%' : '42%'};aspect-ratio:1;object-fit:cover;border-radius:8px">` : ''}<div style="flex:1"><p style="margin:0;font-weight:700;font-size:16px">${this.esc(c.title)}</p><p style="margin:8px 0 12px;font-weight:700;font-size:17px">${c.price}</p>${this.btn(p.button_label, 'left')}</div></div>`;
                }
                case 'products': return p.product_ids.length ? this.grid(p.product_ids.map(find).filter(Boolean), p.columns, p.button_label) : this.card(null);
                case 'smart_products': {
                    const items = this.catalog.filter((c) => c.stock > 0).slice(0, p.count);
                    return `${p.title ? `<h3 style="margin:0 0 8px;font-size:18px;font-weight:700">${this.fill(p.title)}</h3>` : ''}<p style="margin:0 0 8px;font-size:11px;color:#8A919C">✨ ${this.esc(this.sources[p.source])}: picked for each person when sent</p>${this.grid(items.length ? items : [null], p.columns, 'Shop now')}`;
                }
                case 'coupon': {
                    const c = this.coupons.find((x) => x.id === p.coupon_id);
                    return `<div style="border:2px dashed ${this.brand.color};border-radius:14px;padding:20px;text-align:center"><p style="margin:0;color:#5E6672;font-size:14px">${this.fill(p.headline)}</p><p style="margin:6px 0;font-size:28px;font-weight:800">${c ? this.esc(c.off) : 'Pick a coupon'}</p><p style="margin:0 0 12px;font-size:13px;color:#5E6672">${c ? 'with code <b style="font-family:monospace;letter-spacing:2px;color:' + this.brand.color + '">' + this.esc(c.code) + '</b>' : ''}</p>${this.btn(p.button_label)}</div>`;
                }
                case 'columns': return `<div style="display:grid;grid-template-columns:${this.device === 'phone' ? '1fr' : '1fr 1fr'};gap:16px;font-size:14px;line-height:1.6;color:#3D444E"><div>${this.fill(p.left)}</div><div>${this.fill(p.right)}</div></div>`;
                case 'video': {
                    const m = String(p.url).match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|shorts\/|embed\/))([A-Za-z0-9_-]{11})/);
                    return m ? `<img src="https://img.youtube.com/vi/${m[1]}/hqdefault.jpg" style="width:100%;border-radius:10px"><p style="margin:8px 0 0;font-weight:600">▶ ${this.esc(p.caption)}</p>` : '<div style="border:2px dashed #D9DDE3;border-radius:10px;padding:30px;text-align:center;color:#8A919C;font-size:13px">▶ Paste a YouTube link →</div>';
                }
                case 'social': {
                    const on = ['facebook', 'instagram', 'youtube', 'tiktok'].filter((k) => p[k]);
                    return `<div style="text-align:center">${(on.length ? on : ['facebook', 'instagram']).map((k) => `<span style="display:inline-block;margin:0 4px;padding:6px 12px;border:1px solid #D9DDE3;border-radius:999px;font-size:12px;font-weight:600;${on.length ? '' : 'opacity:.4'}">${k.charAt(0).toUpperCase() + k.slice(1)}</span>`).join('')}</div>`;
                }
                case 'divider': return '<hr style="border:0;border-top:1px solid #E6E9ED;margin:6px 0">';
                case 'spacer': return `<div style="height:${p.size}px;background:repeating-linear-gradient(45deg,transparent,transparent 6px,#F5F6F8 6px,#F5F6F8 12px);border-radius:4px"></div>`;
            }
            return '';
        },
    }));

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
