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
    /* Free-hand social designer page (livewire/seller/social-designer.blade.php). Fabric.js is
       loaded only here, so the rest of the site stays light. */
    window.Alpine.data('pmSocialDesigner', (init) => ({
        ...init,
        ed: null, mod: null, ready: false, tab: 'templates', sel: null, layersList: [], thumbs: {}, busy: false, error: '',
        headline: '', tip: '',
        stickers: ['🔥', '⭐', '✅', '🚗', '🔧', '⚡', '💯', '🏁', '🛞', '🎉', '👉', '❗', 'NEW', 'SALE', 'HOT', '★★★★★'],
        colors: ['#E51B2B', '#15191E', '#FFFFFF', '#FFD166', '#18794E', '#1D4ED8', '#7C3AED', '#EA580C', '#F5F6F8', '#5E6672'],
        backgrounds: ['#FFFFFF', '#15191E', '#F5F6F8', '#E51B2B', '#FFD166', ['#15191E', '#E51B2B'], ['#1D4ED8', '#7C3AED'], ['#0E1114', '#5E6672'], ['#EA580C', '#FFD166']],
        async init() {
            const mod = await import('./social-editor');
            this.mod = mod;
            this.ed = mod.createEditor(this.$refs.canvas, this.$refs.wrap, {
                size: this.size,
                onChange: () => { this.layersList = this.ed?.layers() || []; this.sel = this.ed?.props(); },
                onSelect: () => { this.sel = this.ed.props(); this.layersList = this.ed.layers(); },
            });
            await document.fonts?.load?.('800 40px Inter').catch(() => {});
            if (this.canvasJson) await this.ed.load(this.canvasJson, this.size);
            else await this.ed.applyTemplate(this.size === 'story' ? 'story_poster' : 'clean_product', this.data());
            this.ready = true; this.layersList = this.ed.layers(); this.makeThumbs();
            window.addEventListener('designer-image', (e) => this.ed.addImage(e.detail.url, 'Uploaded image'));
        },
        product() { return this.products.find((p) => p.id === this.productId) || this.products[0] || null; },
        data() {
            const p = this.product();
            return { title: p?.title || 'Your part name', price: p?.price || '$49.99', was: p?.was, off: p?.off, fits: p?.fits, image: p?.image,
                store: this.store, logo: this.logo, color: this.color, review: this.reviews[0] || null, headline: this.headline, tip: this.tip };
        },
        async makeThumbs() {
            const size = this.ed.size; const out = {};
            for (const key of Object.keys(this.mod.TEMPLATES)) { try { out[key] = await this.mod.renderThumb(key, size, this.data()); } catch (e) { out[key] = null; } }
            this.thumbs = out;
        },
        async useTemplate(key) { if (this.layersList.length > 2 && !confirm('Replace your design with this template?')) return; await this.ed.applyTemplate(key, this.data()); },
        async changeSize(size) { await this.ed.setSize(size, true); this.size = size; this.makeThumbs(); },
        setProp(k, v) { this.ed.set(k, v); this.sel = this.ed.props(); },
        async save(thenPost = false) {
            this.busy = true; this.error = '';
            try {
                const file = await this.ed.toFile('design.png');
                await new Promise((ok, fail) => this.$wire.upload('png', file, ok, fail));
                await this.$wire.save(this.ed.toJSON(), this.ed.size, thenPost);
            } catch (e) { this.error = 'Could not save. Check your connection and try again.'; }
            this.busy = false;
        },
        download() { const a = document.createElement('a'); a.download = 'panmotive-post.png'; a.href = this.ed.toDataURL(); a.click(); },
        key(e) {
            if (!this.ed || ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || this.ed.canvas.getActiveObject()?.isEditing) return;
            const k = e.key.toLowerCase();
            if ((e.ctrlKey || e.metaKey) && k === 'z') { e.preventDefault(); e.shiftKey ? this.ed.redo() : this.ed.undo(); }
            else if ((e.ctrlKey || e.metaKey) && k === 'y') { e.preventDefault(); this.ed.redo(); }
            else if ((e.ctrlKey || e.metaKey) && k === 'd') { e.preventDefault(); this.ed.duplicate(); }
            else if (k === 'delete' || k === 'backspace') { e.preventDefault(); this.ed.remove(); }
            else if (k.startsWith('arrow')) { e.preventDefault(); const s = e.shiftKey ? 20 : 4; this.ed.nudge(k === 'arrowleft' ? -s : k === 'arrowright' ? s : 0, k === 'arrowup' ? -s : k === 'arrowdown' ? s : 0); }
        },
    }));

    /* Social studio image maker: branded post images in every app's size, drawn on a canvas. */
    window.Alpine.data('pmPostImage', (init) => ({
        ...init,
        design: 'new_arrival', size: 'square', headline: '', sub: '', badge: '', textPos: 'bottom', reviewId: null,
        busy: false, error: '', img: null, logoImg: null,
        sizes: {
            square: { w: 1080, h: 1080, short: 'Square', label: 'Instagram & Facebook · 1080×1080' },
            story: { w: 1080, h: 1920, short: 'Story', label: 'Stories, Reels & TikTok · 1080×1920' },
            wide: { w: 1200, h: 630, short: 'Wide', label: 'Facebook, X & LinkedIn · 1200×630' },
            pin: { w: 1000, h: 1500, short: 'Pin', label: 'Pinterest · 1000×1500' },
        },
        designs: {
            new_arrival: { label: 'New arrival' }, sale: { label: 'Sale' }, fitment: { label: 'Fits your car' }, review: { label: 'Review' },
            restock: { label: 'Back in stock' }, bundle: { label: 'Bundle deal' }, upgrade: { label: 'Upgrade' }, tip: { label: 'Tip of the week' },
        },
        init() {
            if (!this.productId && this.products.length) this.productId = this.products[0].id;
            if (this.logo) { const l = new Image(); l.onload = () => { this.logoImg = l; this.draw(); }; l.src = this.logo; }
            this.applyDesign(); this.load();
        },
        product() { return this.products.find((p) => p.id === this.productId) || null; },
        review() { return this.reviews.find((r) => r.id === this.reviewId) || this.reviews[0] || null; },
        applyDesign() {
            const p = this.product();
            const r = this.review();
            const map = {
                new_arrival: ['Just landed', p?.title || '', 'NEW'],
                sale: ['Sale', p ? (p.was ? `${p.was} → ${p.price}` : p.price) : '', p?.off ? `−${p.off}%` : 'SALE'],
                fitment: [p?.title || '', p?.fits ? `✓ Fits ${p.fits}` : '✓ Check fitment on our listing', 'FITS'],
                review: [r ? `“${r.text}”` : 'Add a 4–5 star review first', r ? `— ${r.name}${r.product ? ', ' + r.product : ''}` : '', '★★★★★'],
                restock: ['Back in stock', p?.title || '', 'RESTOCKED'],
                bundle: ['Bundle & save', p?.title || '', 'DEAL'],
                upgrade: ['Upgrade your ride', p?.title || '', p?.price || ''],
                tip: ['Tip of the week', 'Check your brake pads every 10,000 miles. Squealing or grinding means it’s time.', 'TIP'],
            }[this.design];
            [this.headline, this.sub, this.badge] = map;
        },
        load() {
            const p = this.product();
            this.img = null; this.error = '';
            if (!p) return this.draw();
            const i = new Image();
            i.onload = () => { this.img = i; this.draw(); };
            i.onerror = () => { this.error = 'That product photo could not be loaded.'; this.draw(); };
            i.src = p.image;
        },
        wrap(ctx, text, maxW) {
            const words = String(text).split(/\s+/); const lines = []; let line = '';
            for (const w of words) { const t = line ? line + ' ' + w : w; if (ctx.measureText(t).width > maxW && line) { lines.push(line); line = w; } else line = t; }
            if (line) lines.push(line); return lines;
        },
        cover(ctx, img, x, y, w, h) {
            const s = Math.max(w / img.width, h / img.height); const iw = img.width * s; const ih = img.height * s;
            ctx.save(); ctx.beginPath(); ctx.rect(x, y, w, h); ctx.clip(); ctx.drawImage(img, x + (w - iw) / 2, y + (h - ih) / 2, iw, ih); ctx.restore();
        },
        draw() {
            const c = this.$refs.canvas; if (!c) return;
            const { w, h } = this.sizes[this.size]; c.width = w; c.height = h;
            const ctx = c.getContext('2d'); const u = Math.min(w, h) / 1080; const color = this.color || '#E51B2B';
            const font = (weight, px) => `${weight} ${Math.round(px * u)}px Inter, Arial, sans-serif`;
            ctx.fillStyle = '#15191E'; ctx.fillRect(0, 0, w, h);
            const textOnly = this.design === 'tip' || this.design === 'review';

            if (textOnly) {
                const g = ctx.createLinearGradient(0, 0, w, h); g.addColorStop(0, '#15191E'); g.addColorStop(1, color);
                ctx.fillStyle = g; ctx.fillRect(0, 0, w, h);
                if (this.img && this.design === 'tip') { ctx.globalAlpha = 0.18; this.cover(ctx, this.img, 0, 0, w, h); ctx.globalAlpha = 1; }
            } else if (this.img) {
                if (this.design === 'upgrade' && w > h) { ctx.fillStyle = color; ctx.fillRect(0, 0, w * 0.45, h); this.cover(ctx, this.img, w * 0.45, 0, w * 0.55, h); }
                else this.cover(ctx, this.img, 0, 0, w, h);
            }

            // Readable text band.
            const pad = 70 * u; const top = this.textPos === 'top';
            if (!textOnly && !(this.design === 'upgrade' && w > h)) {
                const g = ctx.createLinearGradient(0, top ? 0 : h, 0, top ? h * 0.6 : h * 0.4);
                g.addColorStop(0, 'rgba(21,25,30,.92)'); g.addColorStop(1, 'rgba(21,25,30,0)');
                ctx.fillStyle = g; ctx.fillRect(0, 0, w, h);
            }

            // Badge.
            if (this.badge) {
                ctx.font = font(800, this.design === 'sale' ? 96 : 40); const bw = ctx.measureText(this.badge).width + 56 * u; const bh = (this.design === 'sale' ? 130 : 72) * u;
                const bx = w - bw - pad; const by = top ? h - bh - pad : pad;
                ctx.fillStyle = this.design === 'fitment' ? '#18794E' : color;
                ctx.beginPath(); ctx.roundRect ? ctx.roundRect(bx, by, bw, bh, 18 * u) : ctx.rect(bx, by, bw, bh); ctx.fill();
                ctx.fillStyle = '#fff'; ctx.textBaseline = 'middle'; ctx.fillText(this.badge, bx + 28 * u, by + bh / 2 + 2 * u);
            }

            // Headline + sub text.
            const area = this.design === 'upgrade' && w > h ? w * 0.45 - pad * 2 : w - pad * 2;
            const big = textOnly ? (this.design === 'review' ? 60 : 84) : 78;
            ctx.textBaseline = 'alphabetic'; ctx.fillStyle = '#fff';
            ctx.font = font(800, big); const hl = this.wrap(ctx, this.headline, area).slice(0, textOnly ? 7 : 3);
            ctx.font = font(600, 40); const sl = this.wrap(ctx, this.sub, area).slice(0, 4);
            const lh = big * 1.12 * u; const sh = 52 * u;
            const block = hl.length * lh + (sl.length ? 24 * u + sl.length * sh : 0);
            let y = textOnly ? (h - block) / 2 + lh * 0.8 : (top ? pad + 140 * u + lh * 0.8 : h - pad - 70 * u - block + lh * 0.8);
            if (this.design === 'upgrade' && w > h) y = (h - block) / 2 + lh * 0.8;
            ctx.font = font(800, big); hl.forEach((l) => { ctx.fillText(l, pad, y); y += lh; });
            if (sl.length) {
                y += 24 * u - lh + sh; ctx.font = font(600, 40);
                ctx.fillStyle = this.design === 'fitment' ? '#6EE7B7' : 'rgba(255,255,255,.9)';
                sl.forEach((l) => { ctx.fillText(l, pad, y); y += sh; });
            }

            // Store mark.
            ctx.font = font(700, 34); ctx.fillStyle = '#fff'; ctx.textBaseline = 'middle';
            const my = top ? h - pad : pad + 20 * u; const name = this.store;
            if (this.logoImg) { const s = 64 * u; ctx.drawImage(this.logoImg, pad, my - s / 2, s, s); ctx.fillText(name, pad + s + 16 * u, my); }
            else { ctx.fillStyle = color; ctx.fillRect(pad, my - 22 * u, 8 * u, 44 * u); ctx.fillStyle = '#fff'; ctx.fillText(name, pad + 22 * u, my); }
            ctx.textBaseline = 'alphabetic';
        },
        file(name) {
            return new Promise((res) => this.$refs.canvas.toBlob((b) => res(new File([b], name, { type: 'image/png' })), 'image/png'));
        },
        async use() {
            this.busy = true; this.error = '';
            try {
                const f = await this.file(`${this.design}-${this.size}.png`);
                await new Promise((ok, fail) => this.$wire.upload('media', f, ok, fail));
                window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Image added to your post.' } }));
            } catch (e) { this.error = 'Could not add the image. Try Download, then upload it.'; }
            this.busy = false;
        },
        download() {
            const a = document.createElement('a'); a.download = `${this.design}-${this.size}.png`; a.href = this.$refs.canvas.toDataURL('image/png'); a.click();
        },
        async downloadAll() {
            const keep = this.size;
            for (const s of Object.keys(this.sizes)) { this.size = s; this.draw(); this.download(); await new Promise((r) => setTimeout(r, 300)); }
            this.size = keep; this.draw();
        },
    }));

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
