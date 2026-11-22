import { Image } from 'expo-image';
import { useEffect } from 'react';
import { StyleSheet, useWindowDimensions, View } from 'react-native';

import type { HostView } from '@/ui/host';
import Animated, { runOnJS, useAnimatedStyle, useReducedMotion, useSharedValue, withDelay, withSpring, withTiming } from 'react-native-reanimated';
import { create } from 'zustand';

import { spring } from '@/theme/motion';
import { radius } from '@/theme/tokens';

/**
 * Card → product morph. When a product card is tapped, its photo lifts off the card and grows into the
 * big photo at the top of the product page, while the page slides in underneath. Then it hands over
 * to the page's own photo. (The website does the same with View Transitions.)
 */
type Rect = { x: number; y: number; w: number; h: number };
type MorphState = { run: number; uri: string | null; from: Rect | null; start: (uri: string, from: Rect) => void; done: () => void };

export const useMorph = create<MorphState>((set, get) => ({
  run: 0,
  uri: null,
  from: null,
  start: (uri, from) => set({ uri, from, run: get().run + 1 }),
  done: () => set({ uri: null, from: null }),
}));

/** Call from a card's onPress with a ref to its image: morphFrom(ref, uri) then navigate. */
export function morphFrom(node: HostView | null, uri: string | null | undefined, after: () => void) {
  if (!node || !uri) return after();
  node.measureInWindow((x, y, w, h) => {
    if (w > 0) useMorph.getState().start(uri, { x, y, w, h });
    after();
  });
}

export function MorphOverlay() {
  const { width } = useWindowDimensions();
  const { run, uri, from } = useMorph();
  const reduced = useReducedMotion();
  const p = useSharedValue(0);
  const fade = useSharedValue(1);

  useEffect(() => {
    if (!run || !from) return;
    if (reduced) {
      useMorph.getState().done();
      return;
    }
    p.value = 0;
    fade.value = 1;
    p.value = withSpring(1, spring.flight);
    // Hand over to the product page's own photo once it has landed.
    fade.value = withDelay(520, withTiming(0, { duration: 220 }, (d) => d && runOnJS(useMorph.getState().done)()));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [run]);

  const style = useAnimatedStyle(() => {
    if (!from) return { opacity: 0 };
    const to = { x: 0, y: 0, w: width, h: width };
    return {
      opacity: fade.value,
      left: from.x + (to.x - from.x) * p.value,
      top: from.y + (to.y - from.y) * p.value,
      width: from.w + (to.w - from.w) * p.value,
      height: from.h + (to.h - from.h) * p.value,
      borderRadius: radius.lg * (1 - p.value),
    };
  });

  if (!uri || !from) return null;

  return (
    <View pointerEvents="none" style={[StyleSheet.absoluteFill, { overflow: 'hidden' }]}>
      <Animated.View style={[styles.img, style]}>
        <Image source={uri} style={StyleSheet.absoluteFill} contentFit="cover" />
      </Animated.View>
    </View>
  );
}

const styles = StyleSheet.create({ img: { position: 'absolute', overflow: 'hidden', backgroundColor: '#222830' } });
