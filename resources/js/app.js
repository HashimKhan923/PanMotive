import './bootstrap';
import { initMotion, refreshMotion, pageTransition, flyToCart } from './motion';

/* --- Theme store (light/dark) — the no-flash class is set in <head> --- */
window.addEventListener('alpine:init', () => {
    window.Alpine.store('theme', {
        dark: document.documentElement.classList.contains('dark'),
        toggle() {
            this.dark = !this.dark;
            document.documentElement.classList.toggle('dark', this.dark);
            try { localStorage.setItem('pm-theme', this.dark ? 'dark' : 'light'); } catch (e) {}
        },
    });

    /* Cart badge count — kept in sync by Livewire 'cart-updated' events. */
    window.Alpine.store('cart', {
        count: Number(document.documentElement.dataset.cartCount || 0),
        set(n) { this.count = Number(n) || 0; },
        bump() { this.count += 1; },
    });
});

window.flyToCart = flyToCart;

document.addEventListener('livewire:init', () => {
    window.Livewire.on('cart-updated', (payload) => {
        const n = Array.isArray(payload) ? payload[0]?.count : payload?.count;
        window.Alpine?.store('cart')?.set(n);
    });
});

function boot() {
    initMotion();
}
document.addEventListener('DOMContentLoaded', boot);
window.addEventListener('load', refreshMotion);
document.addEventListener('livewire:navigated', () => {
    initMotion();
    pageTransition();
});

/* --- PWA service worker --- */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
