import { Image } from 'expo-image';
import { LinearGradient } from 'expo-linear-gradient';
import { useEffect, useRef, useState } from 'react';
import { StyleSheet, View, useWindowDimensions } from 'react-native';
import { Gesture, GestureDetector } from 'react-native-gesture-handler';
import Animated, {
  Easing,
  FadeIn,
  runOnJS,
  useAnimatedStyle,
  useReducedMotion,
  useSharedValue,
  withRepeat,
  withSequence,
  withTiming,
} from 'react-native-reanimated';

import type { Banner } from '@/api/types';
import { openLink } from '@/nav/links';
import { Button } from '@/ui/Button';
import { Logo } from '@/ui/Logo';
import { haptic } from '@/ui/Press';
import { Text } from '@/ui/Text';
import { GUTTER } from '@/ui/Section';
import { useTheme } from '@/theme/theme';
import { palette, radius } from '@/theme/tokens';

const AUTOPLAY_MS = 7000;

/**
 * The admin's homepage banners (Admin → Content → Homepage banners) as a slider — same motion as the
 * website: the charcoal/red forward cut wipes between slides, the photo drifts (Ken Burns), the
 * progress dot fills over 7 s. Swipe to change; holding it pauses. Reduce Motion: no autoplay or drift.
 */
export function BannerSlider({ banners }: { banners: Banner[] }) {
  const { width } = useWindowDimensions();
  const [index, setIndex] = useState(0);
  const [shown, setShown] = useState(0);
  const [paused, setPaused] = useState(false);
  const reduced = useReducedMotion();
  const w = width - GUTTER * 2;
  // The rail grows to fit the slide's words; the wipe bands need its height for their 16° lean.
  const [h, setH] = useState(Math.round(w * 1.1));
  const photoH = Math.round(w * 0.5);
  const sweep = useSharedValue(0);
  const fill = useSharedValue(0);
  const many = banners.length > 1;
  const busy = useRef(false);

  const go = (next: number) => {
    if (busy.current || !many) return;
    const to = (next + banners.length) % banners.length;
    setIndex(to);
    if (reduced) {
      setShown(to);
      return;
    }
    busy.current = true;
    sweep.value = 0;
    sweep.value = withSequence(
      withTiming(1, { duration: 420, easing: Easing.bezier(0.65, 0, 0.35, 1) }, (d) => d && runOnJS(setShown)(to)),
      withTiming(2, { duration: 420, easing: Easing.bezier(0.65, 0, 0.35, 1) }, (d) => d && runOnJS(release)()),
    );
  };
  const release = () => {
    busy.current = false;
  };

  // Autoplay: the active dot fills over 7 s, then the next slide comes in.
  useEffect(() => {
    if (!many || paused || reduced) return;
    fill.value = 0;
    fill.value = withTiming(1, { duration: AUTOPLAY_MS, easing: Easing.linear });
    const t = setTimeout(() => go(index + 1), AUTOPLAY_MS);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [index, paused, many, reduced]);

  const swipe = Gesture.Pan()
    .activeOffsetX([-20, 20])
    .failOffsetY([-12, 12])
    .onBegin(() => runOnJS(setPaused)(true))
    .onEnd((e) => {
      if (Math.abs(e.translationX) > 50) {
        runOnJS(haptic)('selection');
        runOnJS(go)(e.translationX < 0 ? index + 1 : index - 1);
      }
    })
    .onFinalize(() => runOnJS(setPaused)(false));

  const lean = Math.tan((16 * Math.PI) / 180) * (h / 2);
  const bandW = w + lean * 2 + 20;
  // Where a band is for sweep progress p (0 = off left, 1 = covering, 2 = off right).
  const cover = w / 2 - bandW / 2;
  const bandX = (p: number) => {
    'worklet';
    const q = Math.max(0, Math.min(2, p));
    return q <= 1 ? -bandW - lean + (bandW + lean + cover) * q : cover + (w + lean - cover) * (q - 1);
  };
  const charcoal = useAnimatedStyle(() => ({
    transform: [{ translateX: sweep.value <= 0 || sweep.value >= 2 ? -9999 : bandX(sweep.value) }, { skewX: '-16deg' }],
  }));
  // The red band leads a step ahead of the charcoal one.
  const red = useAnimatedStyle(() => ({
    transform: [{ translateX: sweep.value <= 0 || sweep.value >= 2 ? -9999 : bandX(sweep.value + 0.12) }, { skewX: '-16deg' }],
  }));
  const fillStyle = useAnimatedStyle(() => ({ transform: [{ scaleX: fill.value }] }));

  if (!banners.length) return null;
  const b = banners[shown];

  return (
    <GestureDetector gesture={swipe}>
      <View onLayout={(e) => setH(Math.round(e.nativeEvent.layout.height))} style={[styles.rail, { width: w, marginHorizontal: GUTTER }]} accessibilityRole="adjustable" accessibilityLabel={`Promotions, ${index + 1} of ${banners.length}`}>
        <Slide key={b.id} banner={b} photoH={photoH} many={many} />
        <Animated.View pointerEvents="none" style={[styles.band, { width: bandW, height: h * 1.1, backgroundColor: palette.red }, red]} />
        <Animated.View pointerEvents="none" style={[styles.band, { width: bandW, height: h * 1.1, backgroundColor: palette.charcoal }, charcoal]} />
        {many && (
          <View style={styles.controls}>
            {banners.map((x, i) => (
              <View key={x.id} style={[styles.dot, { width: i === index ? 40 : 18 }]}>
                {i === index && <Animated.View style={[styles.dotFill, fillStyle]} />}
              </View>
            ))}
            <Text variant="smallStrong" style={{ color: '#fff', fontSize: 11, marginLeft: 6 }}>
              {String(index + 1).padStart(2, '0')} / {String(banners.length).padStart(2, '0')}
            </Text>
          </View>
        )}
      </View>
    </GestureDetector>
  );
}

function Slide({ banner: b, photoH, many }: { banner: Banner; photoH: number; many: boolean }) {
  const { c } = useTheme();
  const reduced = useReducedMotion();
  const zoom = useSharedValue(1);
  const onDark = b.style !== 'light';

  useEffect(() => {
    if (!reduced) zoom.value = withRepeat(withTiming(1.12, { duration: 16000, easing: Easing.inOut(Easing.quad) }), -1, true);
  }, [reduced, zoom]);
  const kenBurns = useAnimatedStyle(() => ({ transform: [{ scale: zoom.value }] }));

  const bg = b.style === 'red' ? palette.red : b.style === 'light' ? c.surface2 : palette.charcoal;

  return (
    <Animated.View entering={FadeIn.duration(260)} style={{ backgroundColor: bg, paddingBottom: many ? 72 : 24 }}>
      {b.style === 'red' && <LinearGradient colors={['#BB1220', '#BB1220', '#E51B2B']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={StyleSheet.absoluteFill} />}
      {b.image ? (
        <View style={[styles.photo, { height: photoH }]}>
          <Animated.View style={[StyleSheet.absoluteFill, kenBurns]}>
            <Image source={b.image} style={StyleSheet.absoluteFill} contentFit="cover" transition={300} />
          </Animated.View>
          <LinearGradient colors={['transparent', bg]} style={[StyleSheet.absoluteFill, { top: '55%' }]} />
          <View style={[styles.edge, { backgroundColor: b.style === 'red' ? '#fff' : palette.red }]} />
        </View>
      ) : (
        <View style={[styles.photo, styles.mark, { height: photoH * 0.8 }]}>
          <Logo variant="symbol" height={86} onRed={b.style === 'red'} />
        </View>
      )}
      <View style={styles.copy}>
        {b.eyebrow ? (
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
            <View style={{ width: 26, height: 1, backgroundColor: onDark ? 'rgba(255,255,255,0.8)' : palette.red }} />
            <Text variant="tiny" style={{ color: onDark ? 'rgba(255,255,255,0.85)' : c.muted }}>
              {b.eyebrow}
            </Text>
          </View>
        ) : null}
        <Text variant="h1" style={{ color: onDark ? '#fff' : c.fg, marginTop: 10 }}>
          {b.title}
        </Text>
        {b.subtitle ? (
          <Text variant="body" style={{ color: onDark ? 'rgba(255,255,255,0.85)' : c.muted, marginTop: 6 }} numberOfLines={3}>
            {b.subtitle}
          </Text>
        ) : null}
        <View style={{ flexDirection: 'row', gap: 10, marginTop: 16, flexWrap: 'wrap' }}>
          {b.button && <Button title={b.button.label} kind={b.style === 'red' ? 'light' : 'accent'} iconRight="arrow-right" onPress={() => openLink(b.button!.link)} />}
          {b.second && <Button title={b.second.label} kind="outline" onPress={() => openLink(b.second!.link)} />}
        </View>
      </View>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  rail: { borderRadius: radius.xl, overflow: 'hidden' },
  band: { position: 'absolute', top: '-5%', left: 0 },
  photo: { overflow: 'hidden' },
  mark: { alignItems: 'center', justifyContent: 'center' },
  edge: { position: 'absolute', left: 0, right: 0, bottom: 0, height: 4 },
  copy: { paddingHorizontal: 22, paddingTop: 18 },
  controls: {
    position: 'absolute',
    bottom: 16,
    right: 16,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    backgroundColor: 'rgba(0,0,0,0.4)',
    borderRadius: 999,
    paddingHorizontal: 12,
    paddingVertical: 8,
  },
  dot: { height: 5, borderRadius: 3, backgroundColor: 'rgba(255,255,255,0.3)', overflow: 'hidden' },
  dotFill: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: '#fff', transformOrigin: 'left' },
});
