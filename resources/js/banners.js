/*
 * Homepage banners (markup: resources/views/components/home/banners.blade.php).
 *
 * - The rail opens with a rounded clip reveal the first time it scrolls into view.
 * - Each slide's headline rises line by line from behind a mask; the rest follows with a blur-in.
 * - Moving between slides, a two-band forward cut (charcoal + Performance Red) wipes across.
 * - Photos drift slowly (CSS Ken Burns) and lean away from the mouse; a soft light follows it.
 * - Autoplay, shown as a filling progress dot, pauses on hover, keyboard focus, a hidden tab,
 *   or when the rail is off screen. Swipe works on touch screens.
 * Under prefers-reduced-motion nothing moves or plays by itself; the buttons still switch slides.
 */
import gsap from 'gsap';
import { SplitText } from 'gsap/SplitText';

gsap.registerPlugin(SplitText);

const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
const running = [];

export function initBanners() {
    // Livewire navigation reuses the page: stop the old rails before starting the new ones.
    running.splice(0).forEach((stop) => stop());
    document.querySelectorAll('[data-banner-rail]').forEach((rail) => {
        try {
            running.push(setup(rail));
        } catch (e) {
            console.error('[banners] could not start, showing them still', e);
        }
    });
}

function setup(rail) {
    const slides = [...rail.querySelectorAll('[data-banner-slide]')];
    const dots = [...rail.querySelectorAll('[data-banner-dot]')];
    const counter = rail.querySelector('[data-banner-count]');
    const sweep = rail.querySelector('.pm-banner-sweep');
    const seconds = Number(rail.dataset.autoplay || 7);
    const total = slides.length;
    const pad = (n) => String(n).padStart(2, '0');

    let index = 0;
    let busy = false;
    let progress = null;
    let onScreen = false;
    let hovering = false;
    let focused = false;
    let revealed = false;
    const undo = [];
    const on = (target, type, fn, opts) => {
        target.addEventListener(type, fn, opts);
        undo.push(() => target.removeEventListener(type, fn, opts));
    };

    const titleLines = new Map();
    const lines = (slide) => {
        if (!titleLines.has(slide)) {
            const title = slide.querySelector('[data-banner-title]');
            let split = [];
            if (title && !reduce) {
                try {
                    split = SplitText.create(title, { type: 'lines', mask: 'lines', linesClass: 'pm-split-line' }).lines;
                } catch (e) {
                    split = [title];
                }
            }
            titleLines.set(slide, split);
        }
        return titleLines.get(slide);
    };
    const bits = (slide) => slide.querySelectorAll('[data-banner-in]');

    function playIn(slide) {
        if (reduce) return;
        const tl = gsap.timeline();
        const media = slide.querySelector('[data-banner-media]');
        const deco = slide.querySelector('[data-banner-deco]');
        if (media) tl.fromTo(media, { scale: 1.22, xPercent: 8, opacity: 0 }, { scale: 1, xPercent: 0, opacity: 1, duration: 1.5, ease: 'expo.out', clearProps: 'transform,opacity' }, 0);
        if (deco) tl.fromTo(deco, { scale: 0.55, rotate: -14, opacity: 0 }, { scale: 1, rotate: 0, opacity: 1, duration: 1.3, ease: 'back.out(1.7)', clearProps: 'transform,opacity' }, 0.1);
        tl.fromTo(lines(slide), { yPercent: 118, rotate: 2.5 }, { yPercent: 0, rotate: 0, duration: 1.05, ease: 'expo.out', stagger: 0.09 }, 0.15);
        tl.fromTo(bits(slide), { y: 26, opacity: 0, filter: 'blur(10px)' },
            { y: 0, opacity: 1, filter: 'blur(0px)', duration: 0.85, ease: 'expo.out', stagger: 0.1, clearProps: 'filter,transform,opacity' }, 0.32);
        return tl;
    }

    function setActive(next) {
        slides.forEach((slide, i) => {
            const active = i === next;
            slide.classList.toggle('is-active', active);
            slide.inert = !active;
            active ? slide.removeAttribute('aria-hidden') : slide.setAttribute('aria-hidden', 'true');
        });
        dots.forEach((dot, i) => {
            dot.setAttribute('aria-current', i === next ? 'true' : 'false');
            gsap.set(dot.querySelector('[data-fill]'), { scaleX: reduce && i === next ? 1 : 0 });
        });
        if (counter) counter.textContent = `${pad(next + 1)} / ${pad(total)}`;
        index = next;
    }

    function syncPlayback() {
        if (!progress) return;
        onScreen && !hovering && !focused && !document.hidden ? progress.play() : progress.pause();
    }

    function startProgress() {
        progress?.kill();
        progress = null;
        if (total < 2 || reduce) return;
        const fill = dots[index]?.querySelector('[data-fill]');
        progress = gsap.fromTo(fill, { scaleX: 0 }, {
            scaleX: 1, duration: seconds, ease: 'none', paused: true,
            onComplete: () => go(index + 1, 1),
        });
        syncPlayback();
    }

    function go(target, dir) {
        const next = (target + total) % total;
        if (busy || next === index) return;
        const from = slides[index];
        const to = slides[next];

        if (reduce || !sweep) {
            setActive(next);
            startProgress();
            return;
        }

        busy = true;
        progress?.pause();
        const leaving = [...bits(from), ...lines(from)];
        const enterSide = dir > 0 ? -160 : 160;

        gsap.timeline({ onComplete: () => { busy = false; startProgress(); } })
            .set(sweep, { xPercent: enterSide, autoAlpha: 1 })
            .to(leaving, { y: -18, opacity: 0, duration: 0.35, ease: 'power2.in', stagger: 0.025 }, 0)
            .to(sweep, { xPercent: 0, duration: 0.55, ease: 'power4.in' }, 0.05)
            .add(() => {
                setActive(next);
                gsap.set(leaving, { clearProps: 'transform,opacity' });
                playIn(to);
            })
            .to(sweep, { xPercent: -enterSide, duration: 0.8, ease: 'power4.out' })
            .set(sweep, { autoAlpha: 0 });
    }

    // The first time the rail scrolls into view: open it up and play the first slide in.
    function reveal() {
        if (revealed) return;
        revealed = true;
        if (!reduce) {
            gsap.fromTo(rail, { clipPath: 'inset(7% 5% 7% 5% round 36px)', scale: 0.97 },
                { clipPath: 'inset(0% 0% 0% 0% round 24px)', scale: 1, duration: 1.2, ease: 'expo.out', clearProps: 'clipPath,transform' });
            playIn(slides[index]);
        }
        startProgress();
    }

    // Hide the first slide's words before the reveal, only now that we know scripts run.
    if (!reduce) {
        gsap.set(lines(slides[0]), { yPercent: 118 });
        gsap.set(bits(slides[0]), { opacity: 0 });
    }

    const observer = new IntersectionObserver(([entry]) => {
        onScreen = entry.isIntersecting;
        if (onScreen) reveal();
        syncPlayback();
    }, { threshold: 0.3 });
    observer.observe(rail);

    rail.querySelector('[data-banner-prev]') && on(rail.querySelector('[data-banner-prev]'), 'click', () => go(index - 1, -1));
    rail.querySelector('[data-banner-next]') && on(rail.querySelector('[data-banner-next]'), 'click', () => go(index + 1, 1));
    dots.forEach((dot, i) => on(dot, 'click', () => go(i, i > index ? 1 : -1)));

    on(rail, 'keydown', (e) => {
        if (total < 2) return;
        if (e.key === 'ArrowRight') { e.preventDefault(); go(index + 1, 1); }
        if (e.key === 'ArrowLeft') { e.preventDefault(); go(index - 1, -1); }
    });
    on(rail, 'focusin', () => { focused = true; syncPlayback(); });
    on(rail, 'focusout', (e) => { if (!rail.contains(e.relatedTarget)) { focused = false; syncPlayback(); } });
    on(rail, 'pointerenter', () => { hovering = true; syncPlayback(); });
    on(rail, 'pointerleave', () => { hovering = false; syncPlayback(); });
    on(document, 'visibilitychange', syncPlayback);

    // Swipe on touch screens.
    let startX = null;
    on(rail, 'pointerdown', (e) => { if (e.pointerType !== 'mouse') startX = e.clientX; }, { passive: true });
    on(rail, 'pointerup', (e) => {
        if (startX === null) return;
        const dx = e.clientX - startX;
        startX = null;
        if (Math.abs(dx) > 50) go(index + (dx < 0 ? 1 : -1), dx < 0 ? 1 : -1);
    }, { passive: true });

    // Depth: the picture leans away from the mouse and a soft light follows it.
    if (finePointer && !reduce) {
        const layers = () => slides[index].querySelectorAll('[data-banner-depth]');
        on(rail, 'pointermove', (e) => {
            const r = rail.getBoundingClientRect();
            const px = (e.clientX - r.left) / r.width - 0.5;
            const py = (e.clientY - r.top) / r.height - 0.5;
            rail.style.setProperty('--mx', `${(px + 0.5) * 100}%`);
            rail.style.setProperty('--my', `${(py + 0.5) * 100}%`);
            gsap.to(layers(), { x: px * -26, y: py * -18, duration: 0.9, ease: 'power3.out', overwrite: 'auto' });
        });
        on(rail, 'pointerleave', () => gsap.to(layers(), { x: 0, y: 0, duration: 1.1, ease: 'expo.out', overwrite: 'auto' }));
    }

    return () => {
        observer.disconnect();
        progress?.kill();
        undo.forEach((fn) => fn());
    };
}
