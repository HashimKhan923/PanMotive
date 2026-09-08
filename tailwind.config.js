import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** Theme-swappable colors: values come from CSS custom properties set in app.css
 *  (light) and overridden under .dark, so `bg-surface`, `text-fg`, `bg-brand`
 *  etc. all respond to the light/dark toggle. */
function withVar(name) {
    return ({ opacityValue }) =>
        opacityValue === undefined
            ? `rgb(var(${name}))`
            : `rgb(var(${name}) / ${opacityValue})`;
}

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './app/Livewire/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
                display: ['"Figtree"', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                bg: withVar('--c-bg'),
                surface: withVar('--c-surface'),
                'surface-2': withVar('--c-surface-2'),
                fg: withVar('--c-fg'),
                muted: withVar('--c-muted'),
                line: withVar('--c-line'),
                brand: withVar('--c-brand'),
                'brand-fg': withVar('--c-brand-fg'),
                accent: withVar('--c-accent'),
                'accent-fg': withVar('--c-accent-fg'),
            },
            borderColor: {
                DEFAULT: withVar('--c-line'),
            },
            boxShadow: {
                card: '0 1px 2px rgb(15 23 42 / 0.04), 0 8px 24px -12px rgb(15 23 42 / 0.12)',
                'card-hover': '0 2px 4px rgb(15 23 42 / 0.06), 0 16px 40px -16px rgb(15 23 42 / 0.22)',
            },
            keyframes: {
                'fade-up': {
                    '0%': { opacity: '0', transform: 'translateY(12px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                shimmer: {
                    '100%': { transform: 'translateX(100%)' },
                },
            },
            animation: {
                'fade-up': 'fade-up .5s cubic-bezier(.22,1,.36,1) both',
            },
        },
    },

    plugins: [forms],
};
