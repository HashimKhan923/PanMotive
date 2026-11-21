import { useEffect } from 'react';
import { StyleSheet, useWindowDimensions, View } from 'react-native';
import Animated, { interpolate, runOnJS, useAnimatedStyle, useReducedMotion, useSharedValue, withSequence, withTiming } from 'react-native-reanimated';
import { create } from 'zustand';

import { timing } from '@/theme/motion';
import { palette } from '@/theme/tokens';

/**
 * The brand's "forward cut": a charcoal band and a red band, leaning at 16°, sweep across the screen.
 * At the moment the screen is fully covered the change happens underneath (a new section, an order
 * placed), then the bands carry on and reveal it. The same wipe the website's banner slider uses.
 */
type CutState = { run: number; onCovered: (() => void) | null; play: (onCovered?: () => void) => void };

export const useForwardCut = create<CutState>((set, get) => ({
  run: 0,
  onCovered: null,
  play: (onCovered) => set({ run: get().run + 1, onCovered: onCovered ?? null }),
}));

/** Trigger from anywhere: forwardCut(() => router.replace('/(tabs)')). */
export const forwardCut = (onCovered?: () => void) => useForwardCut.getState().play(onCovered);

export function ForwardCutOverlay() {
  const { width, height } = useWindowDimensions();
  const run = useForwardCut((s) => s.run);
  const reduced = useReducedMotion();
  const p = useSharedValue(0); // 0 = off left, 1 = covering, 2 = off right

  const covered = () => {
    const cb = useForwardCut.getState().onCovered;
    useForwardCut.setState({ onCovered: null });
    cb?.();
  };

  useEffect(() => {
    if (run === 0) return;
    if (reduced) {
      covered();
      return;
    }
    p.value = 0;
    p.value = withSequence(
      withTiming(1, timing.cut, (done) => {
        if (done) runOnJS(covered)();
      }),
      withTiming(2, { ...timing.cut, duration: 560 }),
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [run]);

  // Each band leans 16°, so its top and bottom corners sit `lean` px either side of its middle. To
  // cover the whole screen at the midpoint a band must be the screen width plus both leans.
  const H = height * 1.2;
  const lean = Math.tan((16 * Math.PI) / 180) * (H / 2);
  const bandW = width + lean * 2 + 40;
  const start = -bandW - lean;
  const cover = width / 2 - bandW / 2;
  const end = width + lean;
  const charcoal = useAnimatedStyle(() => ({
    transform: [{ translateX: interpolate(p.value, [0, 1, 2], [start, cover, end]) }, { skewX: '-16deg' }],
  }));
  // The red band runs a step ahead, so a red edge leads the sweep.
  const red = useAnimatedStyle(() => ({
    transform: [{ translateX: interpolate(p.value, [0, 0.82, 1.82, 2], [start, cover + width * 0.18, end, end]) }, { skewX: '-16deg' }],
  }));

  return (
    <View pointerEvents="none" style={StyleSheet.absoluteFill}>
      <Animated.View style={[styles.band, { width: bandW, height: H, backgroundColor: palette.red }, red]} />
      <Animated.View style={[styles.band, { width: bandW, height: H, backgroundColor: palette.charcoal }, charcoal]} />
    </View>
  );
}

const styles = StyleSheet.create({ band: { position: 'absolute', top: '-10%', left: 0 } });
