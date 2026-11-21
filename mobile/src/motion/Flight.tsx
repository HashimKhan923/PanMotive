import { Image } from 'expo-image';
import { useEffect } from 'react';
import { StyleSheet, View } from 'react-native';

import type { HostView } from '@/ui/host';
import Animated, { Easing, runOnJS, useAnimatedStyle, useReducedMotion, useSharedValue, withTiming } from 'react-native-reanimated';
import { create } from 'zustand';

import { cartTabTarget } from '@/ui/TabBar';

/**
 * Add-to-cart flight: the product photo shrinks and arcs from where it was tapped into the cart tab.
 * The cart badge then pops (TabBar). With Reduce Motion it's skipped and only the badge updates.
 */
type Flight = { id: number; uri: string; x: number; y: number; size: number };
const useFlights = create<{ list: Flight[]; add: (f: Omit<Flight, 'id'>) => void; drop: (id: number) => void }>((set, get) => ({
  list: [],
  add: (f) => set({ list: [...get().list, { ...f, id: Date.now() + Math.random() }] }),
  drop: (id) => set({ list: get().list.filter((f) => f.id !== id) }),
}));

/** flyToCart(imageRef, uri) — measures where the image is and launches it. */
export function flyToCart(node: HostView | null, uri: string | null | undefined) {
  if (!node || !uri) return;
  node.measureInWindow((x, y, w, h) => {
    const size = Math.min(w, h, 160);
    useFlights.getState().add({ uri, x: x + w / 2 - size / 2, y: y + h / 2 - size / 2, size });
  });
}

export function FlightOverlay() {
  const list = useFlights((s) => s.list);

  return (
    <View pointerEvents="none" style={StyleSheet.absoluteFill}>
      {list.map((f) => (
        <FlightItem key={f.id} flight={f} />
      ))}
    </View>
  );
}

function FlightItem({ flight: f }: { flight: Flight }) {
  const drop = useFlights((s) => s.drop);
  const reduced = useReducedMotion();
  const t = useSharedValue(0);

  useEffect(() => {
    if (reduced) {
      drop(f.id);
      return;
    }
    t.value = withTiming(1, { duration: 720, easing: Easing.bezier(0.55, 0, 0.3, 1) }, (d) => d && runOnJS(drop)(f.id));
  }, [drop, f.id, reduced, t]);

  // Straight line in x, an arc in y (up first, then down into the tab), shrinking and spinning a little.
  const style = useAnimatedStyle(() => {
    const tx = cartTabTarget.x - f.size / 2;
    const ty = cartTabTarget.y - f.size / 2 + 22;
    const x = f.x + (tx - f.x) * t.value;
    const lift = Math.sin(t.value * Math.PI) * -120;
    const y = f.y + (ty - f.y) * t.value * t.value + lift;
    const s = 1 - 0.82 * t.value;
    return {
      transform: [{ translateX: x }, { translateY: y }, { scale: s }, { rotate: `${t.value * -14}deg` }],
      opacity: t.value > 0.92 ? (1 - t.value) / 0.08 : 1,
      borderRadius: 16 + 40 * t.value,
    };
  });

  return (
    <Animated.View style={[styles.item, { width: f.size, height: f.size }, style]}>
      <Image source={f.uri} style={StyleSheet.absoluteFill} contentFit="cover" />
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  item: { position: 'absolute', left: 0, top: 0, overflow: 'hidden', borderWidth: 2, borderColor: '#E51B2B', backgroundColor: '#222830' },
});
