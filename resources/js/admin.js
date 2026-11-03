/* Admin console motion: counting numbers, staggered page entry, cursor glow, and Ctrl+K search.
   Everything is decoration on top of content that is already visible, and all of it stops under
   prefers-reduced-motion. */
import gsap from 'gsap';

const calm = () => matchMedia('(prefers-reduced-motion: reduce)').matches;

function format(value, el) {
    const decimals = Number(el.dataset.decimals ?? 0);
    const text = value.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });

    return `${el.dataset.prefix ?? ''}${text}${el.dataset.suffix ?? ''}`;
}

function countUp() {
    document.querySelectorAll('[data-count]').forEach((el) => {
        const target = Number(el.dataset.count);
        if (calm() || !Number.isFinite(target)) {
            return;
        }
        const state = { v: 0 };
        gsap.to(state, {
            v: target, duration: 1.1, ease: 'power3.out',
            onUpdate: () => { el.textContent = format(state.v, el); },
            onComplete: () => { el.textContent = format(target, el); },
        });
    });
}

function pageEntry() {
    if (calm()) {
        return;
    }
    const blocks = document.querySelectorAll('[data-adm-main] > *, [data-enter] > *');
    gsap.from(blocks, {
        y: 14, opacity: 0, duration: 0.5, ease: 'power3.out', stagger: 0.045, clearProps: 'all',
    });
}

/* One listener instead of one per card: the glow follows the cursor across any .adm-card. */
function cursorGlow() {
    if (calm() || !matchMedia('(pointer: fine)').matches) {
        return;
    }
    document.addEventListener('pointermove', (e) => {
        const card = e.target.closest?.('.adm-card');
        if (!card) {
            return;
        }
        const box = card.getBoundingClientRect();
        card.style.setProperty('--mx', `${e.clientX - box.left}px`);
        card.style.setProperty('--my', `${e.clientY - box.top}px`);
    }, { passive: true });
}

/* The menu stays where you left it between pages. */
function keepNavScroll() {
    const nav = document.querySelector('[data-adm-nav]');
    if (!nav) {
        return;
    }
    try {
        nav.scrollTop = Number(sessionStorage.getItem('adm-nav') ?? 0);
        addEventListener('pagehide', () => sessionStorage.setItem('adm-nav', String(nav.scrollTop)));
    } catch (e) { /* private window */ }
}

function palette(pages, url) {
    return {
        open: false, q: '', rows: pages.slice(0, 8), active: 0, loading: false, ticket: 0,
        show() {
            this.open = true; this.q = ''; this.rows = pages.slice(0, 8); this.active = 0;
            // After the opening transition has started: a hidden input can't take focus.
            setTimeout(() => this.$refs.input.focus(), 60);
        },
        move(by) {
            if (this.rows.length) {
                this.active = (this.active + by + this.rows.length) % this.rows.length;
            }
        },
        go() {
            const row = this.rows[this.active];
            if (row) {
                location.href = row.url;
            }
        },
        async search() {
            const q = this.q.trim().toLowerCase();
            const matched = pages.filter((p) => p.label.toLowerCase().includes(q) || (p.group ?? '').toLowerCase().includes(q)).slice(0, 5);
            this.rows = q ? matched : pages.slice(0, 8);
            this.active = 0;
            if (q.length < 2) {
                return;
            }
            const ticket = ++this.ticket;
            this.loading = true;
            try {
                const res = await fetch(`${url}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
                const found = res.ok ? await res.json() : [];
                if (ticket === this.ticket) {
                    this.rows = [...found, ...matched];
                }
            } finally {
                if (ticket === this.ticket) {
                    this.loading = false;
                }
            }
        },
    };
}

/* Chart hover: find the nearest point to the cursor and move the crosshair and tooltip there. */
function lineChart(points) {
    return {
        i: null,
        hover(e) {
            const box = this.$refs.plot.getBoundingClientRect();
            const x = (e.clientX - box.left) / box.width;
            let best = 0;
            points.forEach((p, idx) => { if (Math.abs(p.x - x) < Math.abs(points[best].x - x)) best = idx; });
            this.i = best;
        },
        get point() { return this.i === null ? null : points[this.i]; },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('admPalette', palette);
    window.Alpine.data('admLineChart', lineChart);
});

document.addEventListener('DOMContentLoaded', () => {
    pageEntry();
    countUp();
    cursorGlow();
    keepNavScroll();
});
