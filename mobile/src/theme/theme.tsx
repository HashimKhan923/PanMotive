import { useMemo } from 'react';

import { usePrefs } from '@/state/prefs';
import { colors, type ColorScheme, type Colors } from '@/theme/tokens';

export type Theme = { scheme: ColorScheme; c: Colors; dark: boolean };

/** The current colours. Dark unless the shopper switched to light (like the website). */
export function useTheme(): Theme {
  const scheme = usePrefs((s) => s.scheme);

  return useMemo(() => ({ scheme, c: colors[scheme], dark: scheme === 'dark' }), [scheme]);
}
