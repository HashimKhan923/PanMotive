import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { Flip } from 'gsap/Flip';

gsap.registerPlugin(ScrollTrigger, Flip);

const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export function initMotion() {
    if (prefersReducedMotion) {
        // Make sure nothing stays hidden if we skip the animations.
        gsap.set('[data-reveal], [data-reveal-group] > *', { clearProps: 'all', opacity: 1 });
        document.querySelectorAll('[data-counter-to]').forEach((el) => {
            el.textContent = Number(el.dataset.counterTo || 0).toLocaleString();
        });
        return;
    }

    // Scroll reveals — data-reveal="fade|slide-up|scale", data-reveal-delay="0.1"
    gsap.utils.toArray('[data-reveal]').forEach((el) => {
        const type = el.dataset.reveal || 'fade';
        const delay = parseFloat(el.dataset.revealDelay || 0);

        const from = {
            fade: { opacity: 0 },
            'slide-up': { opacity: 0, y: 40 },
            scale: { opacity: 0, scale: 0.92 },
        }[type] || { opacity: 0 };

        gsap.from(el, {
            ...from, duration: 0.7, delay, ease: 'power3.out',
            scrollTrigger: { trigger: el, start: 'top 85%', once: true },
        });
    });

    // Staggered groups — data-reveal-group wraps children that animate in sequence
    gsap.utils.toArray('[data-reveal-group]').forEach((group) => {
        gsap.from(group.children, {
            opacity: 0, y: 24, duration: 0.6, ease: 'power2.out', stagger: 0.08,
            scrollTrigger: { trigger: group, start: 'top 85%', once: true },
        });
    });

    // Number counters — data-counter-to="1249"
    gsap.utils.toArray('[data-counter-to]').forEach((el) => {
        const target = parseFloat(el.dataset.counterTo);
        const obj = { val: 0 };
        gsap.to(obj, {
            val: target, duration: 1.4, ease: 'power1.out',
            onUpdate: () => (el.textContent = Math.floor(obj.val).toLocaleString()),
            scrollTrigger: { trigger: el, start: 'top 90%', once: true },
        });
    });
}

/** "Fly to cart" animation — call from wherever an add-to-cart happens */
export function flyToCart(sourceEl, cartIconSelector = '#cart-icon') {
    if (prefersReducedMotion || !sourceEl) return;
    const cartIcon = document.querySelector(cartIconSelector);
    if (!cartIcon) return;

    const sourceRect = sourceEl.getBoundingClientRect();
    const cartRect = cartIcon.getBoundingClientRect();

    const flyer = sourceEl.cloneNode(true);
    flyer.style.cssText = `position:fixed; top:${sourceRect.top}px; left:${sourceRect.left}px; width:${sourceRect.width}px; height:${sourceRect.height}px; z-index:9999; pointer-events:none; border-radius:12px; overflow:hidden;`;
    document.body.appendChild(flyer);

    gsap.to(flyer, {
        top: cartRect.top + cartRect.height / 2,
        left: cartRect.left + cartRect.width / 2,
        width: 20, height: 20, opacity: 0.3, duration: 0.7, ease: 'power2.in',
        onComplete: () => {
            flyer.remove();
            gsap.fromTo(cartIcon, { scale: 1 }, { scale: 1.3, duration: 0.15, yoyo: true, repeat: 1 });
        },
    });
}

/** Smooth page-content transition, hooked into Livewire navigate events */
export function pageTransition() {
    if (prefersReducedMotion) return;
    gsap.from('main', { opacity: 0, y: 12, duration: 0.4, ease: 'power2.out' });
}
