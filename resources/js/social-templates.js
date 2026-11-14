/*
 * Ready-made social post layouts. Each one works in every size (square, story, wide, pin):
 * positions are worked out from the canvas width and height, so nothing is cut off.
 * A layout returns plain element specs; social-editor.js turns them into editable objects.
 *
 * data: { title, price, was, off, fits, store, image, logo, review:{text,name,rating}, tip, headline }
 */

export const SIZES = {
    square: { w: 1080, h: 1080, label: 'Square', hint: 'Instagram & Facebook post' },
    story: { w: 1080, h: 1920, label: 'Story', hint: 'Stories, Reels & TikTok' },
    wide: { w: 1200, h: 630, label: 'Wide', hint: 'Facebook, X, LinkedIn & YouTube' },
    pin: { w: 1000, h: 1500, label: 'Pin', hint: 'Pinterest' },
};

/** Which size each app wants. */
export const PLATFORM_SIZE = {
    facebook: 'square', instagram: 'square', x: 'wide', pinterest: 'pin', tiktok: 'story',
    linkedin: 'wide', google_business: 'square', youtube: 'wide',
};

const FONT = 'Inter, Arial, sans-serif';
const IMPACT = 'Impact, "Arial Black", sans-serif';

const t = (text, o) => ({ type: 'text', text: String(text ?? ''), fontFamily: FONT, fontWeight: 700, fill: '#FFFFFF', ...o });
const r = (o) => ({ type: 'rect', ...o });
const img = (o) => ({ type: 'image', role: 'product', ...o });
const mark = (d, x, y, s, color = '#FFFFFF') => d.logo
    ? [{ type: 'image', role: 'logo', src: d.logo, x, y, w: s, h: s, radius: s / 2 }, t(d.store, { x: x + s + s * 0.3, y: y + s * 0.18, width: 600, fontSize: s * 0.45, fill: color })]
    : [r({ x, y: y + s * 0.15, w: s * 0.12, h: s * 0.7, fill: d.color }), t(d.store, { x: x + s * 0.3, y: y + s * 0.15, width: 700, fontSize: s * 0.5, fill: color })];

export const TEMPLATES = {
    bold_sale: {
        name: 'Bold sale', goal: 'sale',
        build: (w, h, d) => {
            const u = Math.min(w, h) / 1080; const tall = h > w * 1.2; const wide = w > h * 1.3;
            return [
                img({ x: 0, y: 0, w, h, src: d.image }),
                r({ x: 0, y: 0, w, h, gradient: ['rgba(21,25,30,0)', 'rgba(21,25,30,.92)'], gradientY: [0.35, 1] }),
                { type: 'circle', x: w - 300 * u - 50 * u, y: 60 * u, radius: 150 * u, fill: d.color },
                t(d.off ? `-${d.off}%` : 'SALE', { x: w - 350 * u, y: 160 * u, width: 300 * u, fontSize: 92 * u, fontFamily: IMPACT, textAlign: 'center' }),
                t('SALE', { x: 60 * u, y: h - (tall ? 620 : wide ? 330 : 470) * u, width: w - 120 * u, fontSize: (wide ? 120 : 170) * u, fontFamily: IMPACT, charSpacing: 40 }),
                t(d.title, { x: 60 * u, y: h - (tall ? 400 : wide ? 170 : 270) * u, width: w - 120 * u, fontSize: 46 * u, fontWeight: 600 }),
                t(d.was ? `${d.was}  →  ${d.price}` : d.price, { x: 60 * u, y: h - (tall ? 250 : wide ? 90 : 140) * u, width: w - 120 * u, fontSize: 56 * u, fontWeight: 800, fill: '#FFD166' }),
                ...mark(d, 60 * u, 50 * u, 70 * u),
            ];
        },
    },
    clean_product: {
        name: 'Clean product', goal: 'new',
        build: (w, h, d) => {
            const u = Math.min(w, h) / 1080; const wide = w > h * 1.3;
            const box = wide ? { x: w * 0.52, y: 50 * u, w: w * 0.44, h: h - 100 * u } : { x: 80 * u, y: 130 * u, w: w - 160 * u, h: h * 0.55 };
            const ty = wide ? 150 * u : box.y + box.h + 50 * u;
            const tx = wide ? 70 * u : 80 * u; const tw = wide ? w * 0.42 : w - 160 * u;
            return [
                r({ x: 0, y: 0, w, h, fill: '#F5F6F8' }),
                r({ x: box.x, y: box.y, w: box.w, h: box.h, fill: '#FFFFFF', rx: 36 * u, shadow: true }),
                img({ x: box.x + 30 * u, y: box.y + 30 * u, w: box.w - 60 * u, h: box.h - 60 * u, src: d.image, radius: 22 * u }),
                t(d.title, { x: tx, y: ty, width: tw, fontSize: (wide ? 54 : 58) * u, fill: '#15191E', fontWeight: 800 }),
                t(d.price, { x: tx, y: ty + (wide ? 210 : 170) * u, width: tw, fontSize: 64 * u, fill: d.color, fontWeight: 800 }),
                r({ x: 0, y: 0, w, h: 14 * u, fill: d.color }),
                ...mark(d, tx, wide ? 50 * u : 30 * u, 64 * u, '#15191E'),
            ];
        },
    },
    split_color: {
        name: 'Split colour', goal: 'new',
        build: (w, h, d) => {
            const u = Math.min(w, h) / 1080; const vertical = h > w;
            const panel = vertical ? { x: 0, y: h * 0.58, w, h: h * 0.42 } : { x: 0, y: 0, w: w * 0.46, h };
            const photo = vertical ? { x: 0, y: 0, w, h: h * 0.58 } : { x: w * 0.46, y: 0, w: w * 0.54, h };
            const px = panel.x + 60 * u; const pw = panel.w - 120 * u;
            return [
                img({ ...photo, src: d.image }),
                r({ ...panel, fill: d.color }),
                t(d.headline || 'Upgrade your ride', { x: px, y: panel.y + 70 * u, width: pw, fontSize: 72 * u, fontWeight: 900, lineHeight: 1.05 }),
                t(d.title, { x: px, y: panel.y + panel.h - 260 * u, width: pw, fontSize: 38 * u, fontWeight: 600 }),
                t(d.price, { x: px, y: panel.y + panel.h - 130 * u, width: pw, fontSize: 60 * u, fontWeight: 800 }),
            ];
        },
    },
    fitment_check: {
        name: 'Fits your car', goal: 'fitment',
        build: (w, h, d) => {
            const u = Math.min(w, h) / 1080; const wide = w > h * 1.3;
            const ph = wide ? h : h * 0.6;
            const px = wide ? w * 0.5 : 0; const pw = wide ? w * 0.5 : w;
            const bx = wide ? 60 * u : 60 * u; const by = wide ? 90 * u : ph + 60 * u; const bw = wide ? w * 0.42 : w - 120 * u;
            return [
                r({ x: 0, y: 0, w, h, fill: '#FFFFFF' }),
                img({ x: px, y: 0, w: pw, h: ph, src: d.image }),
                r({ x: bx, y: by, w: 300 * u, h: 76 * u, fill: '#18794E', rx: 38 * u }),
                t('✓ FITS', { x: bx, y: by + 14 * u, width: 300 * u, fontSize: 42 * u, textAlign: 'center', fontWeight: 800 }),
                t(d.fits || 'Your vehicle', { x: bx, y: by + 110 * u, width: bw, fontSize: 56 * u, fill: '#15191E', fontWeight: 800 }),
                t(d.title, { x: bx, y: by + 200 * u, width: bw, fontSize: 36 * u, fill: '#5E6672', fontWeight: 500 }),
                t(d.price, { x: bx, y: by + (wide ? 340 : 300) * u, width: bw, fontSize: 54 * u, fill: d.color, fontWeight: 800 }),
            ];
        },
    },
    new_frame: {
        name: 'New arrival frame', goal: 'new',
        build: (w, h, d) => {
            const u = Math.min(w, h) / 1080; const f = 40 * u;
            return [
                r({ x: 0, y: 0, w, h, fill: d.color }),
                img({ x: f, y: f, w: w - f * 2, h: h - f * 2, src: d.image, radius: 24 * u }),
                r({ x: f, y: h - f - 300 * u, w: w - f * 2, h: 300 * u, gradient: ['rgba(21,25,30,0)', 'rgba(21,25,30,.9)'], gradientY: [0, 1] }),
                r({ x: f + 40 * u, y: f + 40 * u, w: 380 * u, h: 80 * u, fill: '#FFFFFF', rx: 40 * u }),
                t('NEW ARRIVAL', { x: f + 40 * u, y: f + 58 * u, width: 380 * u, fontSize: 38 * u, textAlign: 'center', fill: d.color, fontWeight: 900, charSpacing: 60 }),
                t(d.title, { x: f + 40 * u, y: h - f - 230 * u, width: w - f * 2 - 80 * u, fontSize: 50 * u, fontWeight: 800 }),
                t(d.price, { x: f + 40 * u, y: h - f - 110 * u, width: w - f * 2 - 80 * u, fontSize: 46 * u, fontWeight: 700, fill: '#FFD166' }),
            ];
        },
    },
    price_tag: {
        name: 'Price tag', goal: 'sale',
        build: (w, h, d) => {
            const u = Math.min(w, h) / 1080;
            return [
                img({ x: 0, y: 0, w, h, src: d.image }),
                r({ x: w - 520 * u, y: h - 330 * u, w: 470 * u, h: 220 * u, fill: '#FFD166', rx: 24 * u, angle: -6, shadow: true }),
                t(d.price, { x: w - 510 * u, y: h - 290 * u, width: 450 * u, fontSize: 96 * u, fontFamily: IMPACT, fill: '#15191E', textAlign: 'center', angle: -6 }),
                t(d.was ? `was ${d.was}` : 'today only', { x: w - 510 * u, y: h - 175 * u, width: 450 * u, fontSize: 34 * u, fill: '#15191E', textAlign: 'center', fontWeight: 600, angle: -6 }),
                r({ x: 0, y: 0, w, h: 150 * u, gradient: ['rgba(21,25,30,.75)', 'rgba(21,25,30,0)'], gradientY: [0, 1] }),
                ...mark(d, 50 * u, 40 * u, 66 * u),
            ];
        },
    },
    review_card: {
        name: 'Customer review', goal: 'review',
        build: (w, h, d) => {
            const u = Math.min(w, h) / 1080; const rv = d.review || { text: 'Perfect fit and fast shipping. Would buy again!', name: 'Happy customer', rating: 5 };
            return [
                r({ x: 0, y: 0, w, h, gradient: ['#15191E', d.color], gradientX: [0, 1], gradientY: [0, 1] }),
                t('“', { x: 70 * u, y: 40 * u, width: 300 * u, fontSize: 300 * u, fontFamily: 'Georgia, serif', fill: 'rgba(255,255,255,.25)' }),
                t('★'.repeat(rv.rating || 5), { x: 80 * u, y: h * 0.25, width: w - 160 * u, fontSize: 70 * u, fill: '#FFD166' }),
                t(rv.text, { x: 80 * u, y: h * 0.25 + 120 * u, width: w - 160 * u, fontSize: (w > h * 1.3 ? 44 : 56) * u, fontWeight: 700, lineHeight: 1.25 }),
                t(`— ${rv.name}`, { x: 80 * u, y: h - 230 * u, width: w - 160 * u, fontSize: 38 * u, fontWeight: 500, fill: 'rgba(255,255,255,.85)' }),
                ...mark(d, 80 * u, h - 130 * u, 64 * u),
            ];
        },
    },
    tip_card: {
        name: 'Tip of the week', goal: 'tip',
        build: (w, h, d) => {
            const u = Math.min(w, h) / 1080;
            return [
                r({ x: 0, y: 0, w, h, fill: '#15191E' }),
                img({ x: 0, y: 0, w, h, src: d.image, opacity: 0.18 }),
                r({ x: 80 * u, y: 110 * u, w: 210 * u, h: 210 * u, fill: d.color, rx: 40 * u }),
                t('TIP', { x: 80 * u, y: 160 * u, width: 210 * u, fontSize: 92 * u, fontFamily: IMPACT, textAlign: 'center' }),
                t(d.headline || 'Tip of the week', { x: 80 * u, y: 380 * u, width: w - 160 * u, fontSize: 64 * u, fontWeight: 900 }),
                t(d.tip || 'Check your brake pads every 10,000 miles. Squealing or grinding means it’s time for new ones.', { x: 80 * u, y: 500 * u, width: w - 160 * u, fontSize: 44 * u, fontWeight: 500, lineHeight: 1.35, fill: 'rgba(255,255,255,.9)' }),
                ...mark(d, 80 * u, h - 140 * u, 64 * u),
            ];
        },
    },
    restock_banner: {
        name: 'Back in stock', goal: 'restock',
        build: (w, h, d) => {
            const u = Math.min(w, h) / 1080;
            return [
                img({ x: 0, y: 0, w, h, src: d.image }),
                r({ x: -100 * u, y: h * 0.42, w: w + 200 * u, h: 150 * u, fill: d.color, angle: -8, shadow: true }),
                t('BACK IN STOCK', { x: 0, y: h * 0.42 + 30 * u, width: w, fontSize: 96 * u, fontFamily: IMPACT, textAlign: 'center', angle: -8, charSpacing: 30 }),
                r({ x: 0, y: h - 240 * u, w, h: 240 * u, gradient: ['rgba(21,25,30,0)', 'rgba(21,25,30,.9)'], gradientY: [0, 1] }),
                t([d.title, d.price].filter(Boolean).join("  ·  "), { x: 60 * u, y: h - 130 * u, width: w - 120 * u, fontSize: 42 * u, fontWeight: 700 }),
            ];
        },
    },
    minimal_dark: {
        name: 'Minimal dark', goal: 'new',
        build: (w, h, d) => {
            const u = Math.min(w, h) / 1080; const s = Math.min(w, h) * 0.55; const wide = w > h * 1.3;
            const cx = wide ? w * 0.72 : w / 2; const cy = wide ? h / 2 : h * 0.42;
            return [
                r({ x: 0, y: 0, w, h, fill: '#0E1114' }),
                { type: 'circle', x: cx - s / 2 - 16 * u, y: cy - s / 2 - 16 * u, radius: s / 2 + 16 * u, fill: d.color },
                img({ x: cx - s / 2, y: cy - s / 2, w: s, h: s, src: d.image, circle: true }),
                t(String(d.title || '').toUpperCase(), { x: wide ? 70 * u : 70 * u, y: wide ? 170 * u : cy + s / 2 + 80 * u, width: wide ? w * 0.45 : w - 140 * u, fontSize: 46 * u, fontWeight: 800, textAlign: wide ? 'left' : 'center', charSpacing: 40 }),
                t(d.price, { x: wide ? 70 * u : 70 * u, y: wide ? 380 * u : cy + s / 2 + 230 * u, width: wide ? w * 0.45 : w - 140 * u, fontSize: 70 * u, fontWeight: 800, fill: d.color, textAlign: wide ? 'left' : 'center' }),
            ];
        },
    },
    deal_burst: {
        name: 'Deal burst', goal: 'sale',
        build: (w, h, d) => {
            const u = Math.min(w, h) / 1080;
            return [
                r({ x: 0, y: 0, w, h, fill: '#FFD166' }),
                img({ x: w * 0.08, y: h * 0.18, w: w * 0.84, h: h * 0.55, src: d.image, radius: 30 * u }),
                { type: 'star', x: w - 360 * u, y: 40 * u, radius: 170 * u, fill: d.color },
                t(d.off ? `-${d.off}%` : 'DEAL', { x: w - 360 * u, y: 160 * u, width: 340 * u, fontSize: 84 * u, fontFamily: IMPACT, textAlign: 'center' }),
                t('Hot deal', { x: 70 * u, y: 60 * u, width: 600 * u, fontSize: 80 * u, fontFamily: IMPACT, fill: '#15191E' }),
                t(d.title, { x: w * 0.08, y: h * 0.76, width: w * 0.84, fontSize: 44 * u, fill: '#15191E', fontWeight: 800 }),
                t(d.was ? `${d.price}   (was ${d.was})` : d.price, { x: w * 0.08, y: h * 0.76 + 120 * u, width: w * 0.84, fontSize: 50 * u, fill: '#15191E', fontWeight: 800 }),
            ];
        },
    },
    story_poster: {
        name: 'Story poster', goal: 'new',
        build: (w, h, d) => {
            const u = Math.min(w, h) / 1080; const ph = h * 0.62;
            return [
                r({ x: 0, y: 0, w, h, fill: '#15191E' }),
                img({ x: 0, y: 0, w, h: ph, src: d.image }),
                r({ x: 0, y: ph - 200 * u, w, h: 200 * u, gradient: ['rgba(21,25,30,0)', '#15191E'], gradientY: [0, 1] }),
                t(d.headline || 'Shop the look', { x: 70 * u, y: ph + 20 * u, width: w - 140 * u, fontSize: 76 * u, fontWeight: 900 }),
                t(d.title, { x: 70 * u, y: ph + 150 * u, width: w - 140 * u, fontSize: 40 * u, fontWeight: 500, fill: 'rgba(255,255,255,.85)' }),
                t(d.price, { x: 70 * u, y: ph + 260 * u, width: w - 140 * u, fontSize: 60 * u, fontWeight: 800, fill: '#FFD166' }),
                r({ x: 70 * u, y: h - 190 * u, w: w - 140 * u, h: 100 * u, fill: d.color, rx: 50 * u }),
                t('Shop now · link in bio', { x: 70 * u, y: h - 165 * u, width: w - 140 * u, fontSize: 40 * u, textAlign: 'center', fontWeight: 800 }),
            ];
        },
    },
};

/** A good layout for a goal on a given app (so a multi-app post doesn't look the same everywhere). */
export function pickTemplate(goal, platform) {
    const byGoal = {
        sale: ['bold_sale', 'price_tag', 'deal_burst'],
        new: ['clean_product', 'new_frame', 'minimal_dark', 'split_color'],
        fitment: ['fitment_check', 'clean_product'],
        restock: ['restock_banner', 'new_frame'],
        review: ['review_card'],
        tip: ['tip_card'],
    }[goal] || ['clean_product'];
    if (PLATFORM_SIZE[platform] === 'story') return goal === 'review' ? 'review_card' : goal === 'tip' ? 'tip_card' : 'story_poster';
    const order = Object.keys(PLATFORM_SIZE);
    return byGoal[Math.max(0, order.indexOf(platform)) % byGoal.length];
}
