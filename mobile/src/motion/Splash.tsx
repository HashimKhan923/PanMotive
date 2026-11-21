import * as SplashScreen from 'expo-splash-screen';
import { useEffect, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated, {
  Easing,
  runOnJS,
  useAnimatedStyle,
  useReducedMotion,
  useSharedValue,
  withDelay,
  withSequence,
  withSpring,
  withTiming,
} from 'react-native-reanimated';

import { Logo } from '@/ui/Logo';
import { spring, timing } from '@/theme/motion';
import { palette } from '@/theme/tokens';

/**
 * The opening: on charcoal, the red P slides in along the 16° cut and lands with a spring, a red flash
 * runs across it, the wordmark rises in, then the whole splash slides away on the forward cut.
 * Takes ~1.3 s; with Reduce Motion it simply fades.
 */
export function Splash({ ready }: { ready: boolean }) {
  const [gone, setGone] = useState(false);
  const reduced = useReducedMotion();

  const mark = useSharedValue(0);
  const word = useSharedValue(0);
  const flash = useSharedValue(0);
  const exit = useSharedValue(0);

  useEffect(() => {
    // Swap the native splash (same charcoal + red P) for this one without a flicker.
    void SplashScreen.hideAsync();
    mark.value = withSpring(1, spring.gentle);
    flash.value = withDelay(320, withTiming(1, { duration: 700, easing: Easing.bezier(0.65, 0, 0.35, 1) }));
    word.value = withDelay(420, withTiming(1, timing.slow));
  }, [flash, mark, word]);

  useEffect(() => {
    if (!ready) return;
    const finish = () => setGone(true);
    exit.value = withDelay(
      reduced ? 0 : 650,
      withSequence(withTiming(reduced ? 1 : 1, { duration: reduced ? 250 : 520, easing: Easing.bezier(0.65, 0, 0.35, 1) }, (d) => d && runOnJS(finish)())),
    );
  }, [ready, exit, reduced]);

  const markStyle = useAnimatedStyle(() => ({
    opacity: mark.value,
    transform: [{ translateX: (1 - mark.value) * -60 }, { translateY: (1 - mark.value) * 17 }, { scale: 0.8 + mark.value * 0.2 }],
  }));
  const wordStyle = useAnimatedStyle(() => ({ opacity: word.value, transform: [{ translateY: (1 - word.value) * 14 }] }));
  const flashStyle = useAnimatedStyle(() => ({ transform: [{ translateX: -260 + flash.value * 520 }, { skewX: '-16deg' }], opacity: flash.value < 1 ? 0.9 : 0 }));
  const container = useAnimatedStyle(() =>
    reduced
      ? { opacity: 1 - exit.value }
      : { transform: [{ translateX: `${exit.value * 110}%` }, { skewX: `${-exit.value * 6}deg` }] },
  );

  if (gone) return null;

  return (
    <Animated.View style={[StyleSheet.absoluteFill, styles.root, container]} pointerEvents={ready ? 'none' : 'auto'}>
      <View style={styles.center}>
        <Animated.View style={markStyle}>
          <Logo variant="symbol" height={78} />
        </Animated.View>
        <View style={styles.flashClip} pointerEvents="none">
          <Animated.View style={[styles.flash, flashStyle]} />
        </View>
        <Animated.View style={[{ marginTop: 22 }, wordStyle]}>
          <Logo variant="primary" height={22} wordColor="#FFFFFF" />
        </Animated.View>
      </View>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  root: { backgroundColor: palette.charcoal, zIndex: 100 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  flashClip: { position: 'absolute', width: 220, height: 120, overflow: 'hidden', top: '50%', marginTop: -100 },
  flash: { position: 'absolute', top: 0, bottom: 0, width: 26, backgroundColor: 'rgba(255,255,255,0.18)' },
});
