/**
 * Panmotive design tokens — the same palette as the website (resources/css/app.css, Brand Guidelines
 * 11/2026): Performance Red #E51B2B, Charcoal #15191E, neutrals and status pairs. Dark is the default.
 */

export const palette = {
  red: '#E51B2B',
  redHover: '#CC1726',
  redPressed: '#BB1220',
  charcoal: '#15191E',
  white: '#FFFFFF',
} as const;

export type ColorScheme = 'dark' | 'light';

export type Colors = Record<
  | 'bg' | 'surface' | 'surface2' | 'line' | 'fg' | 'muted' | 'input' | 'accent' | 'accentPressed' | 'accentFg' | 'accentInk'
  | 'success' | 'successBg' | 'warning' | 'warningBg' | 'error' | 'errorBg' | 'overlay' | 'tabBar' | 'shadow',
  string
>;

const dark: Colors = {
  bg: '#15191E',
  surface: '#222830',
  surface2: '#2B323C',
  line: '#3E4652',
  fg: '#FFFFFF',
  muted: '#C6CBD2',
  input: '#7D8798',
  accent: palette.red,
  accentPressed: palette.redPressed,
  accentFg: '#FFFFFF',
  /** Red text is only allowed on white: on dark the "accent" text colour is white. */
  accentInk: '#FFFFFF',
  success: '#6EE7A8',
  successBg: '#143628',
  warning: '#FCD34D',
  warningBg: '#3A2C0E',
  error: '#FCA5A5',
  errorBg: '#42181D',
  overlay: 'rgba(8, 10, 13, 0.62)',
  tabBar: 'rgba(21, 25, 30, 0.86)',
  shadow: '#000000',
};

const light: Colors = {
  bg: '#FFFFFF',
  surface: '#FFFFFF',
  surface2: '#F5F6F8',
  line: '#D9DDE3',
  fg: '#15191E',
  muted: '#5E6672',
  input: '#667085',
  accent: palette.red,
  accentPressed: palette.redPressed,
  accentFg: '#FFFFFF',
  accentInk: '#BB1220',
  success: '#18794E',
  successBg: '#E8F5EE',
  warning: '#8A5200',
  warningBg: '#FFF4D6',
  error: '#BB1220',
  errorBg: '#FDEBED',
  overlay: 'rgba(21, 25, 30, 0.45)',
  tabBar: 'rgba(255, 255, 255, 0.88)',
  shadow: '#15191E',
};

export const colors = { dark, light };

export const space = { xxs: 2, xs: 4, sm: 8, md: 12, lg: 16, xl: 20, xxl: 24, xxxl: 32, huge: 48 } as const;

/** The website rounds cards at 16px (rounded-2xl) and banners at 24px (rounded-3xl). */
export const radius = { sm: 8, md: 12, lg: 16, xl: 24, pill: 999 } as const;

export const font = {
  regular: 'Inter_400Regular',
  medium: 'Inter_500Medium',
  semibold: 'Inter_600SemiBold',
  bold: 'Inter_700Bold',
} as const;

/** Type scale (size / line height). Headlines are tight like the website's, but never so tight lines touch. */
export const type = {
  hero: { fontSize: 40, lineHeight: 44, letterSpacing: -1.2, fontFamily: font.bold },
  h1: { fontSize: 28, lineHeight: 34, letterSpacing: -0.6, fontFamily: font.bold },
  h2: { fontSize: 22, lineHeight: 28, letterSpacing: -0.4, fontFamily: font.bold },
  h3: { fontSize: 17, lineHeight: 22, letterSpacing: -0.2, fontFamily: font.semibold },
  body: { fontSize: 15, lineHeight: 22, fontFamily: font.regular },
  bodyStrong: { fontSize: 15, lineHeight: 22, fontFamily: font.semibold },
  small: { fontSize: 13, lineHeight: 18, fontFamily: font.regular },
  smallStrong: { fontSize: 13, lineHeight: 18, fontFamily: font.semibold },
  tiny: { fontSize: 11, lineHeight: 14, fontFamily: font.semibold, letterSpacing: 1.4, textTransform: 'uppercase' as const },
  price: { fontSize: 17, lineHeight: 22, fontFamily: font.bold, letterSpacing: -0.3 },
  priceLarge: { fontSize: 28, lineHeight: 32, fontFamily: font.bold, letterSpacing: -0.8 },
} as const;

/** The brand's forward cut: every diagonal in the app leans at 16°, like the website's red bands. */
export const CUT_DEG = 16;
