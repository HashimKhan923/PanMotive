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

/** Status colors come only from the brand palette, whichever Tailwind shade a view asks for. */
function palette(name) {
    return Object.fromEntries([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950].map((shade) => [shade, withVar(name)]));
}

/** The guide's neutrals, for the plain Tailwind grays that older pages and printed documents use. */
const neutral = { 50: '#F5F6F8', 100: '#F5F6F8', 200: '#D9DDE3', 300: '#D9DDE3', 400: '#5E6672', 500: '#5E6672', 600: '#5E6672', 700: '#222830', 800: '#222830', 900: '#15191E', 950: '#15191E' };

/** Gray text follows the theme, so it stays readable in dark mode too. */
const textNeutral = {
    ...Object.fromEntries([50, 100, 200, 300, 400, 500, 600].map((shade) => [shade, withVar('--c-muted')])),
    ...Object.fromEntries([700, 800, 900, 950].map((shade) => [shade, withVar('--c-fg')])),
};

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
            spacing: {
                '4.5': '1.125rem', // used for icon sizing (w-4.5 / h-4.5)
            },
            fontFamily: {
                sans: ['Inter', 'Arial', ...defaultTheme.fontFamily.sans],
                display: ['Inter', 'Arial', ...defaultTheme.fontFamily.sans],
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
                chart: withVar('--c-chart'),
                input: withVar('--c-input'),
                // Charcoal replaces pure black in interface and print.
                black: '#15191E',
                gray: neutral,
                slate: neutral,
                zinc: neutral,
                neutral,
                'accent-hover': withVar('--c-accent-hover'),
                'accent-pressed': withVar('--c-accent-pressed'),
                'warning-bg': withVar('--c-warning-bg'),
                emerald: palette('--c-success'),
                green: palette('--c-success'),
                amber: palette('--c-warning'),
                yellow: palette('--c-warning'),
                orange: palette('--c-warning'),
                rose: palette('--c-error'),
                red: palette('--c-error'),
                sky: palette('--c-fg'),
                blue: palette('--c-fg'),
                indigo: palette('--c-fg'),
                violet: palette('--c-fg'),
                purple: palette('--c-fg'),
                cyan: palette('--c-fg'),
                teal: palette('--c-fg'),
            },
            textColor: {
                accent: withVar('--c-accent-ink'),
                gray: textNeutral,
                slate: textNeutral,
                zinc: textNeutral,
                neutral: textNeutral,
            },
            borderColor: {
                DEFAULT: withVar('--c-line'),
            },
            boxShadow: {
                card: '0 1px 2px rgb(21 25 30 / 0.04), 0 8px 24px -12px rgb(21 25 30 / 0.12)',
                'card-hover': '0 2px 4px rgb(21 25 30 / 0.06), 0 16px 40px -16px rgb(21 25 30 / 0.22)',
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
