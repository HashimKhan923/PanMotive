import { memo } from 'react';
import Svg, { G, Path } from 'react-native-svg';

import { LOGO_MARK, LOGO_WORD } from '@/ui/logo-paths';
import { useTheme } from '@/theme/theme';

type Props = {
  variant?: 'primary' | 'stacked' | 'symbol';
  height?: number;
  /** On a red field the whole logo goes white (brand rule). */
  onRed?: boolean;
  /** Wordmark colour; defaults to the text colour, so it reads full-colour on white and reverse on charcoal. */
  wordColor?: string;
};

/** The Panmotive logo — the same lockups as the website's x-ui.logo. */
export const Logo = memo(function Logo({ variant = 'primary', height = 26, onRed = false, wordColor }: Props) {
  const { c } = useTheme();
  const symbol = onRed ? '#FFFFFF' : '#E51B2B';
  const word = onRed ? '#FFFFFF' : wordColor ?? c.fg;

  if (variant === 'symbol') {
    return (
      <Svg height={height} width={(height * 503) / 335} viewBox="0 0 503 335" accessibilityLabel="Panmotive">
        <Path fill={symbol} d={LOGO_MARK} />
      </Svg>
    );
  }

  if (variant === 'stacked') {
    return (
      <Svg height={height} width={(height * 504) / 237.9} viewBox="0 0 504 237.9" accessibilityLabel="Panmotive">
        <G transform="translate(150 0) scale(0.4056)"><Path fill={symbol} d={LOGO_MARK} /></G>
        <G transform="translate(0 178.9)"><Path fill={word} d={LOGO_WORD} /></G>
      </Svg>
    );
  }

  return (
    <Svg height={height} width={(height * 2346.4) / 335} viewBox="0 0 2346.4 335" accessibilityLabel="Panmotive">
      <Path fill={symbol} d={LOGO_MARK} />
      <G transform="translate(586.4 129) scale(3.4921)"><Path fill={word} d={LOGO_WORD} /></G>
    </Svg>
  );
});
