import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
let booted = false;

/** Force every reveal element visible — the safety net if anything goes wrong. */
function revealAll() {
    gsap.set('[data-reveal], [data-reveal-group] > *', { opacity: 1, y: 0, scale: 1, clearProps: 'opacity,transform' });
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
            const from = {
                fade: { opacity: 0 },
                'slide-up': { opacity: 0, y: 40 },
                'slide-right': { opacity: 0, x: -40 },
                scale: { opacity: 0, scale: 0.94 },
            }[type] || { opacity: 0 };

            const aboveFold = el.getBoundingClientRect().top < vh * 0.9;

            // Above-the-fold content plays immediately on load — no ScrollTrigger,
            // so it can never get stuck hidden by a mis-timed trigger calc.
            if (aboveFold) {
                gsap.fromTo(el, from, { opacity: 1, x: 0, y: 0, scale: 1, duration: 0.7, delay, ease: 'power3.out' });
            } else {
                gsap.fromTo(el, from, {
                    opacity: 1, x: 0, y: 0, scale: 1, duration: 0.7, ease: 'power3.out',
                    scrollTrigger: { trigger: el, start: 'top 88%', once: true },
                });
            }
        });

        gsap.utils.toArray('[data-reveal-group]').forEach((group) => {
            const aboveFold = group.getBoundingClientRect().top < vh * 0.9;
            const tween = {
                opacity: 1, y: 0, duration: 0.6, ease: 'power2.out', stagger: 0.07,
            };
            gsap.fromTo(group.children, { opacity: 0, y: 24 },
                aboveFold ? tween : { ...tween, scrollTrigger: { trigger: group, start: 'top 90%', once: true } });
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

/** "Fly to cart" — clone the product image and arc it into #cart-icon. */
export function flyToCart(sourceEl, cartIconSelector = '#cart-icon') {
    if (reduce || !sourceEl) return;
    const cartIcon = document.querySelector(cartIconSelector);
    if (!cartIcon) return;

    const img = sourceEl.querySelector('img') || sourceEl;
    const s = img.getBoundingClientRect();
    const c = cartIcon.getBoundingClientRect();

    const flyer = img.cloneNode(true);
    flyer.style.cssText =
        `position:fixed;top:${s.top}px;left:${s.left}px;width:${s.width}px;height:${s.height}px;` +
        `z-index:9999;pointer-events:none;border-radius:14px;object-fit:cover;box-shadow:0 20px 40px -12px rgba(0,0,0,.4)`;
    document.body.appendChild(flyer);

    gsap.to(flyer, {
        top: c.top + c.height / 2, left: c.left + c.width / 2,
        width: 16, height: 16, opacity: 0.2, duration: 0.75, ease: 'power2.in',
        onComplete: () => {
            flyer.remove();
            gsap.fromTo(cartIcon, { scale: 1 }, { scale: 1.35, duration: 0.16, yoyo: true, repeat: 1, ease: 'power2.out' });
        },
    });
}

export function pageTransition() {
    if (reduce) return;
    gsap.fromTo('main', { opacity: 0, y: 10 }, { opacity: 1, y: 0, duration: 0.35, ease: 'power2.out' });
}
