import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { SplitText } from 'gsap/SplitText';

gsap.registerPlugin(ScrollTrigger, SplitText);

const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
// Pointer effects (tilt, magnetic) only for real mice — never on touch screens.
const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
let booted = false;

/** Force every reveal element visible — the safety net if anything goes wrong. */
function revealAll() {
    gsap.set('[data-reveal], [data-reveal-group] > *', { opacity: 1, y: 0, scale: 1, clearProps: 'opacity,transform,clipPath' });
}

export function initMotion() {
    try {
        // Clean up any triggers from a previous page (Livewire SPA navigation).
        ScrollTrigger.getAll().forEach((t) => t.kill());

        if (reduce) {
            revealAll();
            document.querySelectorAll('[data-counter-to]').forEach((el) => {
                el.textContent = Number(el.dataset.counterTo || 0).toLocaleString();
            });
            return;
        }

        const vh = window.innerHeight;

        gsap.utils.toArray('[data-reveal]').forEach((el) => {
            const type = el.dataset.reveal || 'fade';
            const delay = parseFloat(el.dataset.revealDelay || 0);
            if (type === 'mask') {
                gsap.fromTo(el, { clipPath: 'inset(0 100% 0 0)' }, {
                    clipPath: 'inset(0 0% 0 0)', duration: 0.9, delay, ease: 'expo.out',
                    scrollTrigger: el.getBoundingClientRect().top < vh * 0.9 ? undefined : { trigger: el, start: 'top 88%', once: true },
                });
                return;
            }
            const from = {
                fade: { opacity: 0 },
                'slide-up': { opacity: 0, y: 40 },
                'slide-right': { opacity: 0, x: -40 },
                scale: { opacity: 0, scale: 0.94 },
                blur: { opacity: 0, y: 24, filter: 'blur(12px)' },
            }[type] || { opacity: 0 };

            const aboveFold = el.getBoundingClientRect().top < vh * 0.9;

            // Above-the-fold content plays immediately on load — no ScrollTrigger,
            // so it can never get stuck hidden by a mis-timed trigger calc.
            const to = { opacity: 1, x: 0, y: 0, scale: 1, filter: 'blur(0px)', duration: 0.8, ease: 'expo.out', clearProps: 'filter,transform' };
            if (aboveFold) {
                gsap.fromTo(el, from, { ...to, delay });
            } else {
                gsap.fromTo(el, from, { ...to, scrollTrigger: { trigger: el, start: 'top 88%', once: true } });
            }
        });

        gsap.utils.toArray('[data-reveal-group]').forEach((group) => {
            const aboveFold = group.getBoundingClientRect().top < vh * 0.9;
            const tween = {
                opacity: 1, y: 0, scale: 1, duration: 0.7, ease: 'expo.out', stagger: { each: 0.06, from: 'start' },
                clearProps: 'transform', // hand transform back to CSS (card tilt) once revealed
            };
            gsap.fromTo(group.children, { opacity: 0, y: 28, scale: 0.98 },
                aboveFold ? tween : { ...tween, scrollTrigger: { trigger: group, start: 'top 90%', once: true } });
        });

        // Parallax — subtle scroll-scrubbed drift. data-parallax="0.2" = 20% of scroll.
        gsap.utils.toArray('[data-parallax]').forEach((el) => {
            const depth = parseFloat(el.dataset.parallax || '0.2');
            gsap.to(el, {
                yPercent: depth * 40,
                ease: 'none',
                scrollTrigger: { trigger: el.closest('section') || el, start: 'top bottom', end: 'bottom top', scrub: true },
            });
        });

        gsap.utils.toArray('[data-counter-to]').forEach((el) => {
            const target = parseFloat(el.dataset.counterTo) || 0;
            const obj = { v: 0 };
            gsap.to(obj, {
                v: target, duration: 1.4, ease: 'power1.out',
                onUpdate: () => (el.textContent = Math.floor(obj.v).toLocaleString()),
                scrollTrigger: { trigger: el, start: 'top 92%', once: true },
            });
        });

        splitHeadlines();
        scrollProgress();
        stickyBuyBar();
        if (finePointer) {
            magnetic();
        }

        ScrollTrigger.refresh();
        booted = true;
    } catch (e) {
        console.error('[motion] init failed — showing all content', e);
        revealAll();
    }
}

/** Re-check trigger positions once fonts/images settle. */
export function refreshMotion() {
    if (booted && !reduce) ScrollTrigger.refresh();
}

/**
 * "Fly to cart": a copy of the product photo arcs into the header cart icon, which bumps on landing.
 *
 * The flyer is a brand-new <img>, never cloneNode(): cloning copied the gallery photo's Alpine
 * bindings (:src / :style), Alpine re-ran them outside their component, threw, and wiped the
 * flyer's position:fixed — so it fell to the bottom of the page instead of flying.
 */
export function flyToCart(sourceEl, cartIconSelector = '#cart-icon') {
    if (reduce || !sourceEl) return;
    const cartIcon = document.querySelector(cartIconSelector);
    const img = sourceEl.tagName === 'IMG' ? sourceEl : sourceEl.querySelector('img');
    const src = img?.currentSrc || img?.src;
    if (!cartIcon || !src) return;

    // If the smart header has slid away (you've scrolled down), bring it back first so the
    // cart icon is on screen to land on.
    const header = document.querySelector('header.site-header');
    const headerHidden = header?.classList.contains('-translate-y-full');
    if (headerHidden) window.dispatchEvent(new CustomEvent('pm:show-header'));

    const s = img.getBoundingClientRect();
    const size = Math.min(s.width, s.height, 220); // a big gallery photo flies as a neat thumbnail
    const flyer = new Image();
    flyer.src = src;
    flyer.alt = '';
    flyer.setAttribute('aria-hidden', 'true');
    flyer.style.cssText =
        `position:fixed;left:0;top:0;width:${size}px;height:${size}px;margin:0;z-index:9999;pointer-events:none;` +
        `border-radius:18px;object-fit:cover;box-shadow:0 24px 48px -12px rgba(21,25,30,.45);will-change:transform,opacity;` +
        `transform:translate(${s.left + (s.width - size) / 2}px, ${s.top + (s.height - size) / 2}px)`;
    document.body.appendChild(flyer);

    const launch = () => {
        const c = cartIcon.getBoundingClientRect();
        const startX = s.left + (s.width - size) / 2;
        const startY = s.top + (s.height - size) / 2;
        const endX = c.left + c.width / 2 - size / 2;
        const endY = c.top + c.height / 2 - size / 2;
        // Different easing on each axis bends the straight line into a curve that stays on screen:
        // it sweeps sideways first (x eases out), then swings up into the cart (y eases in).
        const tl = gsap.timeline({
            onComplete: () => {
                flyer.remove();
                cartIcon.classList.remove('pm-bump');
                void cartIcon.offsetWidth; // restart the CSS animation
                cartIcon.classList.add('pm-bump');
            },
        });
        tl.set(flyer, { x: startX, y: startY, transformOrigin: '50% 50%' })
            .to(flyer, { scale: 1.06, duration: 0.12, ease: 'power2.out' })
            .to(flyer, { x: endX, duration: 0.75, ease: 'power2.out' }, 0.1)
            .to(flyer, { y: endY, duration: 0.75, ease: 'power3.in' }, 0.1)
            .to(flyer, { scale: 0.12, rotate: 18, borderRadius: '50%', duration: 0.75, ease: 'power2.in' }, 0.1)
            .to(flyer, { opacity: 0.35, duration: 0.2 }, 0.65);
    };

    headerHidden ? setTimeout(launch, 320) : launch();
}

export function pageTransition() {
    if (reduce) return;
    // clearProps: a leftover transform on <main> would trap position:fixed children (sticky buy bar).
    gsap.fromTo('main', { opacity: 0, y: 10 }, { opacity: 1, y: 0, duration: 0.35, ease: 'power2.out', clearProps: 'transform,opacity' });
}

/** Headlines marked data-split rise in word by word from behind a mask. */
function splitHeadlines() {
    document.querySelectorAll('[data-split]').forEach((el) => {
        if (el.dataset.splitDone) return;
        el.dataset.splitDone = '1';
        const split = SplitText.create(el, { type: 'words,lines', linesClass: 'pm-split-line', mask: 'lines' });
        // Gradient text is painted with background-clip, which doesn't reach the new word boxes —
        // hand the gradient to each word that came from a gradient span.
        split.words.forEach((w) => { if (w.closest('.pm-gradient-text') && w !== w.closest('.pm-gradient-text')) w.classList.add('pm-gradient-text'); });
        gsap.from(split.words, {
            yPercent: 110, opacity: 0, rotate: 4, duration: 0.9, ease: 'expo.out', stagger: 0.045,
            delay: parseFloat(el.dataset.splitDelay || 0.1),
        });
    });
}

/** Thin accent bar showing how far down the page you are. */
function scrollProgress() {
    let bar = document.querySelector('.pm-progress');
    if (!bar) {
        bar = document.createElement('div');
        bar.className = 'pm-progress';
        bar.setAttribute('aria-hidden', 'true');
        document.body.appendChild(bar);
    }
    gsap.to(bar, {
        scaleX: 1, ease: 'none',
        scrollTrigger: { trigger: document.documentElement, start: 'top top', end: 'bottom bottom', scrub: 0.3 },
    });
}

/** Buttons marked data-magnetic lean toward the cursor a little. */
function magnetic() {
    document.querySelectorAll('[data-magnetic]').forEach((el) => {
        if (el.dataset.magDone) return;
        el.dataset.magDone = '1';
        const strength = parseFloat(el.dataset.magnetic || 0.25);
        const xTo = gsap.quickTo(el, 'x', { duration: 0.5, ease: 'elastic.out(1, 0.4)' });
        const yTo = gsap.quickTo(el, 'y', { duration: 0.5, ease: 'elastic.out(1, 0.4)' });
        el.addEventListener('pointermove', (e) => {
            const r = el.getBoundingClientRect();
            xTo((e.clientX - r.left - r.width / 2) * strength);
            yTo((e.clientY - r.top - r.height / 2) * strength);
        });
        el.addEventListener('pointerleave', () => { xTo(0); yTo(0); });
    });
}

/** On the product page, a compact buy bar appears once the main Add to Cart scrolls away. */
function stickyBuyBar() {
    const bar = document.querySelector('[data-sticky-buy]');
    const anchor = document.querySelector('[data-buy-anchor]');
    if (!bar || !anchor) return;
    ScrollTrigger.create({
        trigger: anchor, start: 'bottom top+=64',
        onEnter: () => bar.classList.add('is-visible'),
        onLeaveBack: () => bar.classList.remove('is-visible'),
    });
}

/**
 * Pointer-follow 3D tilt + glare on [data-tilt] cards. One delegated listener for the whole page,
 * so it also works for cards Livewire adds later (filters, pagination).
 */
export function initTilt() {
    if (reduce || !finePointer || window.__pmTilt) return;
    window.__pmTilt = true;
    let active = null;
    document.addEventListener('pointermove', (e) => {
        const card = e.target.closest?.('[data-tilt]');
        if (active && active !== card) { active.classList.remove('is-tilting'); active = null; }
        if (!card) return;
        active = card;
        const r = card.getBoundingClientRect();
        const px = (e.clientX - r.left) / r.width;
        const py = (e.clientY - r.top) / r.height;
        card.style.setProperty('--ry', `${(px - 0.5) * 6}deg`);
        card.style.setProperty('--rx', `${(0.5 - py) * 6}deg`);
        card.style.setProperty('--gx', `${px * 100}%`);
        card.style.setProperty('--gy', `${py * 100}%`);
        card.classList.add('is-tilting');
    }, { passive: true });
    document.addEventListener('pointerleave', () => active?.classList.remove('is-tilting'));
}

/**
 * Shared-element page transition: the product photo you click morphs into the product page's
 * main photo. The view-transition-name is given to just that one image at click time, so it is
 * always unique (the same product can appear twice on the home page).
 */
export function initProductMorph() {
    if (reduce || !('onpagereveal' in window || 'startViewTransition' in document)) return;
    document.addEventListener('click', (e) => {
        const link = e.target.closest?.('a[data-morph]');
        if (!link || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
        const img = link.querySelector('img');
        if (!img) return;
        // Names must be unique per page — e.g. on a product page the hero photo already has it.
        document.querySelectorAll('[data-vt-hero]').forEach((el) => { el.style.viewTransitionName = 'none'; });
        img.style.viewTransitionName = 'product-hero';
    });
    // Coming back with the browser's Back button: clear the stale name so it can't clash.
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('a[data-morph] img').forEach((i) => { i.style.viewTransitionName = ''; });
    });
}

/** Cards Livewire adds (filters, sorting, pagination) rise in with a short stagger instead of popping. */
let addedQueue = [];
export function animateAdded(el) {
    if (reduce || el.nodeType !== 1 || !el.matches?.('[data-tilt]')) return;
    addedQueue.push(el);
    if (addedQueue.length === 1) {
        requestAnimationFrame(() => {
            gsap.fromTo(addedQueue, { opacity: 0, y: 18, scale: 0.98 },
                // Opacity stays set: inside a [data-reveal-group] the stylesheet starts children at 0.
                { opacity: 1, y: 0, scale: 1, duration: 0.5, ease: 'expo.out', stagger: 0.035, clearProps: 'transform' });
            addedQueue = [];
        });
    }
}
