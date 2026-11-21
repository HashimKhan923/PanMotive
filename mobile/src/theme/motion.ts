import { Easing, ReduceMotion, type WithSpringConfig, type WithTimingConfig } from 'react-native-reanimated';

/**
 * One motion language for the whole app. Every animation uses these springs and timings so the app
 * feels like one product. Reduce Motion (phone setting) is respected everywhere: ReduceMotion.System
 * makes Reanimated jump straight to the end.
 */
export const spring = {
  /** Buttons, chips, hearts — quick with a small overshoot. */
  snappy: { damping: 16, stiffness: 320, mass: 0.7, reduceMotion: ReduceMotion.System } satisfies WithSpringConfig,
  /** Cards, sheets, page elements settling into place. */
  gentle: { damping: 20, stiffness: 180, mass: 0.9, reduceMotion: ReduceMotion.System } satisfies WithSpringConfig,
  /** Big moves: the add-to-cart flight, hero morphs. */
  flight: { damping: 22, stiffness: 140, mass: 1, reduceMotion: ReduceMotion.System } satisfies WithSpringConfig,
  /** Badges and counters popping. */
  pop: { damping: 9, stiffness: 380, mass: 0.6, reduceMotion: ReduceMotion.System } satisfies WithSpringConfig,
};

/** The website's signature ease: cubic-bezier(.22, 1, .36, 1) (expo-out-like). */
export const easeOut = Easing.bezier(0.22, 1, 0.36, 1);
export const easeInOut = Easing.bezier(0.65, 0, 0.35, 1);

export const timing = {
  fast: { duration: 180, easing: easeOut, reduceMotion: ReduceMotion.System } satisfies WithTimingConfig,
  base: { duration: 320, easing: easeOut, reduceMotion: ReduceMotion.System } satisfies WithTimingConfig,
  slow: { duration: 560, easing: easeOut, reduceMotion: ReduceMotion.System } satisfies WithTimingConfig,
  cut: { duration: 620, easing: easeInOut, reduceMotion: ReduceMotion.System } satisfies WithTimingConfig,
};

/** Stagger between items appearing in a list or grid (ms). */
export const STAGGER = 55;
