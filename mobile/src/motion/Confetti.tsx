import { useEffect, useMemo } from 'react';
import { StyleSheet, View, useWindowDimensions } from 'react-native';
import Animated, { Easing, useAnimatedStyle, useReducedMotion, useSharedValue, withDelay, withTiming } from 'react-native-reanimated';

import { palette } from '@/theme/tokens';

const COLORS = [palette.red, palette.red, '#FFFFFF', palette.charcoal, '#BB1220', '#C6CBD2'];

/**
 * A burst of brand confetti — red, white and charcoal slivers cut at the brand's 16° — that shoots up
 * from the bottom and tumbles down. For the moment an order is placed. Skipped with Reduce Motion.
 */
export function Confetti({ count = 70 }: { count?: number }) {
  const reduced = useReducedMotion();
  const { width, height } = useWindowDimensions();

  const bits = useMemo(
    () =>
      Array.from({ length: count }, (_, i) => ({
        x: width / 2 + (Math.random() - 0.5) * 60,
        vx: (Math.random() - 0.5) * width * 1.3,
        up: height * (0.45 + Math.random() * 0.45),
        spin: (Math.random() - 0.5) * 1440,
        w: 6 + Math.random() * 6,
        h: 10 + Math.random() * 10,
        color: COLORS[i % COLORS.length],
        delay: Math.random() * 180,
      })),
    [count, width, height],
  );

  if (reduced) return null;

  return (
    <View pointerEvents="none" style={[StyleSheet.absoluteFill, { overflow: 'hidden' }]}>
      {bits.map((b, i) => (
        <Bit key={i} {...b} floor={height} />
      ))}
    </View>
  );
}

function Bit({ x, vx, up, spin, w, h, color, delay, floor }: { x: number; vx: number; up: number; spin: number; w: number; h: number; color: string; delay: number; floor: number }) {
  const t = useSharedValue(0);

  useEffect(() => {
    t.value = withDelay(delay, withTiming(1, { duration: 2400, easing: Easing.out(Easing.quad) }));
  }, [delay, t]);

  // A simple throw: up fast, then gravity pulls it back down past the bottom of the screen.
  const style = useAnimatedStyle(() => {
    const p = t.value;
    const y = floor - up * (2 * p - p * p * 1.6);
    return {
      opacity: p < 0.85 ? 1 : (1 - p) / 0.15,
      transform: [{ translateX: x + vx * p }, { translateY: y }, { rotate: `${spin * p}deg` }, { skewX: '-16deg' }],
    };
  });

  return <Animated.View style={[{ position: 'absolute', left: 0, top: 0, width: w, height: h, backgroundColor: color, borderRadius: 1 }, style]} />;
}
