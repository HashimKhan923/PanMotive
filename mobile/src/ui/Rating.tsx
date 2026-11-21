import { View } from 'react-native';
import Svg, { Path } from 'react-native-svg';

import { Text } from '@/ui/Text';
import { useTheme } from '@/theme/theme';

const STAR = 'M12 2.5l2.94 5.96 6.56.95-4.75 4.63 1.12 6.54L12 17.5l-5.87 3.08 1.12-6.54L2.5 9.41l6.56-.95L12 2.5z';

/** Five stars with partial fill, plus an optional count. */
export function Rating({ value, count, size = 12 }: { value: number; count?: number | null; size?: number }) {
  const { c } = useTheme();
  if (value <= 0) return null;

  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }} accessibilityLabel={`Rated ${value.toFixed(1)} out of 5`}>
      <View style={{ flexDirection: 'row' }}>
        {[1, 2, 3, 4, 5].map((i) => {
          const fill = Math.max(0, Math.min(1, value - (i - 1)));
          return (
            <View key={i} style={{ width: size, height: size }}>
              <Svg width={size} height={size} viewBox="0 0 24 24" style={{ position: 'absolute' }}>
                <Path d={STAR} fill={c.line} />
              </Svg>
              <View style={{ width: size * fill, height: size, overflow: 'hidden', position: 'absolute' }}>
                <Svg width={size} height={size} viewBox="0 0 24 24">
                  <Path d={STAR} fill={c.fg} />
                </Svg>
              </View>
            </View>
          );
        })}
      </View>
      {count != null && (
        <Text variant="small" tone="muted">
          ({count})
        </Text>
      )}
    </View>
  );
}
