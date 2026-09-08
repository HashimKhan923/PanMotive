import './bootstrap';
import { initMotion, pageTransition, flyToCart } from './motion';

window.flyToCart = flyToCart; // exposed for Livewire wire:click hooks

document.addEventListener('DOMContentLoaded', initMotion);
document.addEventListener('livewire:navigated', () => {
    initMotion();
    pageTransition();
});

// Register the service worker (hand-rolled PWA — see public/sw.js).
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
