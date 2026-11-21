import { Text as RNText, type TextProps } from 'react-native';

import { useTheme } from '@/theme/theme';
import { type as typeScale } from '@/theme/tokens';

type Variant = keyof typeof typeScale;
type Tone = 'fg' | 'muted' | 'accent' | 'success' | 'warning' | 'error' | 'inverse';

type Props = TextProps & { variant?: Variant; tone?: Tone; center?: boolean };

/** All text in the app goes through here: Inter, the brand type scale, and the brand's colour rules. */
export function Text({ variant = 'body', tone = 'fg', center, style, ...rest }: Props) {
  const { c } = useTheme();
  const color = {
    fg: c.fg,
    muted: c.muted,
    // Red text only on white; on dark the brand's "accent" text is white (website: text-accent → accent-ink).
    accent: c.accentInk,
    success: c.success,
    warning: c.warning,
    error: c.error,
    inverse: c.bg,
  }[tone];

  return <RNText {...rest} allowFontScaling maxFontSizeMultiplier={1.4} style={[typeScale[variant], { color }, center && { textAlign: 'center' }, style]} />;
}
