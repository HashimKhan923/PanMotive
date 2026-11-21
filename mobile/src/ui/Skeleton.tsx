import { LinearGradient } from 'expo-linear-gradient';
import { useEffect } from 'react';
import { StyleSheet, View, type DimensionValue, type StyleProp, type ViewStyle } from 'react-native';
import Animated, { Easing, ReduceMotion, useAnimatedStyle, useSharedValue, withRepeat, withTiming } from 'react-native-reanimated';

import { useTheme } from '@/theme/theme';
import { radius as R } from '@/theme/tokens';

type Props = { width?: DimensionValue; height?: DimensionValue; radius?: number; style?: StyleProp<ViewStyle> };

/** Content-shaped placeholder with a light sweeping across at the brand's 16° — instead of spinners. */
export function Skeleton({ width = '100%', height = 16, radius = R.sm, style }: Props) {
  const { c, dark } = useTheme();
  const x = useSharedValue(-1);

  useEffect(() => {
    x.value = withRepeat(withTiming(1, { duration: 1300, easing: Easing.inOut(Easing.quad), reduceMotion: ReduceMotion.System }), -1, false);
  }, [x]);

  const sweep = useAnimatedStyle(() => ({ transform: [{ translateX: `${x.value * 120}%` }, { skewX: '-16deg' }] }));

  return (
    <View style={[{ width, height, borderRadius: radius, backgroundColor: c.surface2, overflow: 'hidden' }, style]}>
      <Animated.View style={[StyleSheet.absoluteFill, sweep]}>
        <LinearGradient
          colors={['transparent', dark ? 'rgba(255,255,255,0.07)' : 'rgba(21,25,30,0.06)', 'transparent']}
          start={{ x: 0, y: 0.5 }}
          end={{ x: 1, y: 0.5 }}
          style={StyleSheet.absoluteFill}
        />
      </Animated.View>
    </View>
  );
}

/** A product card's shape while it loads. */
export function CardSkeleton({ width }: { width: number }) {
  const { c } = useTheme();

  return (
    <View style={{ width, borderRadius: R.lg, backgroundColor: c.surface, borderWidth: 1, borderColor: c.line, overflow: 'hidden' }}>
      <Skeleton height={width} radius={0} />
      <View style={{ padding: 12, gap: 8 }}>
        <Skeleton width="45%" height={10} />
        <Skeleton height={13} />
        <Skeleton width="70%" height={13} />
        <Skeleton width="40%" height={18} style={{ marginTop: 4 }} />
      </View>
    </View>
  );
}
