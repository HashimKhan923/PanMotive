import { LinearGradient } from 'expo-linear-gradient';
import { router } from 'expo-router';
import { useState } from 'react';
import { FlatList, StyleSheet, View, useWindowDimensions } from 'react-native';
import Animated, { Extrapolation, interpolate, useAnimatedScrollHandler, useAnimatedStyle, useSharedValue, type SharedValue } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { forwardCut } from '@/motion/ForwardCut';
import { usePrefs } from '@/state/prefs';
import { Button } from '@/ui/Button';
import { Icon } from '@/ui/Icon';
import { Logo } from '@/ui/Logo';
import { Press } from '@/ui/Press';
import { Text } from '@/ui/Text';
import { palette } from '@/theme/tokens';

const SLIDES = [
  { icon: 'vehicle', title: 'The right part,\nevery time.', body: 'Add your vehicle once. Every part then shows whether it fits.' },
  { icon: 'shield', title: 'Payment held\nuntil delivery.', body: 'Sellers are paid after your order arrives. Parts listed for your vehicle are covered by the Fit Guarantee.' },
  { icon: 'sparkles', title: 'Cashback on\nevery delivery.', body: 'Earn wallet credit on delivered orders and spend it at checkout.' },
];

const AList = Animated.createAnimatedComponent(FlatList<(typeof SLIDES)[number]>);

/** First launch: three slides on charcoal, then "Add your vehicle". The forward cut wipes into the app. */
export default function WelcomeScreen() {
  const { width } = useWindowDimensions();
  const insets = useSafeAreaInsets();
  const setOnboarded = usePrefs((s) => s.setOnboarded);
  const x = useSharedValue(0);
  const [i, setI] = useState(0);

  const onScroll = useAnimatedScrollHandler((e) => {
    x.value = e.contentOffset.x;
  });

  const finish = (garage: boolean) => {
    setOnboarded();
    forwardCut(() => {
      router.replace('/');
      if (garage) setTimeout(() => router.push('/garage'), 50);
    });
  };

  return (
    <View style={{ flex: 1, backgroundColor: palette.charcoal }}>
      <LinearGradient colors={['rgba(229,27,43,0.25)', 'rgba(21,25,30,0)']} start={{ x: 1, y: 0 }} end={{ x: 0.3, y: 0.6 }} style={StyleSheet.absoluteFill} />
      <View style={[styles.top, { paddingTop: insets.top + 12 }]}>
        <Logo height={22} wordColor="#fff" />
        <Press onPress={() => finish(false)} accessibilityLabel="Skip">
          <Text variant="smallStrong" style={{ color: 'rgba(255,255,255,0.7)' }}>
            Skip
          </Text>
        </Press>
      </View>

      <AList
        data={SLIDES}
        horizontal
        pagingEnabled
        showsHorizontalScrollIndicator={false}
        onScroll={onScroll}
        scrollEventThrottle={16}
        onMomentumScrollEnd={(e) => setI(Math.round(e.nativeEvent.contentOffset.x / width))}
        keyExtractor={(s) => s.icon}
        renderItem={({ item, index }) => <Slide item={item} index={index} x={x} width={width} />}
      />

      <View style={[styles.bottom, { paddingBottom: insets.bottom + 20 }]}>
        <View style={styles.dots}>
          {SLIDES.map((s, k) => (
            <Dot key={s.icon} k={k} x={x} width={width} />
          ))}
        </View>
        <Button title="Add your vehicle" icon="vehicle" size="lg" block onPress={() => finish(true)} />
        <Button title={i < SLIDES.length - 1 ? 'Start shopping' : 'Start shopping'} kind="ghost" style={{ marginTop: 6 }} onPress={() => finish(false)} />
      </View>
    </View>
  );
}

function Slide({ item, index, x, width }: { item: (typeof SLIDES)[number]; index: number; x: SharedValue<number>; width: number }) {
  // Each slide's icon and words move at different speeds as you swipe (depth).
  const iconStyle = useAnimatedStyle(() => {
    const p = (x.value - index * width) / width;
    return { transform: [{ translateX: p * -120 }, { rotate: `${p * -20}deg` }, { scale: interpolate(Math.abs(p), [0, 1], [1, 0.6], Extrapolation.CLAMP) }], opacity: 1 - Math.min(1, Math.abs(p)) };
  });
  const textStyle = useAnimatedStyle(() => {
    const p = (x.value - index * width) / width;
    return { transform: [{ translateX: p * -40 }], opacity: 1 - Math.min(1, Math.abs(p) * 1.4) };
  });

  return (
    <View style={{ width, paddingHorizontal: 28, justifyContent: 'center' }}>
      <Animated.View style={[styles.iconWrap, iconStyle]}>
        <View style={styles.iconCut} />
        <Icon name={item.icon} size={48} color="#fff" />
      </Animated.View>
      <Animated.View style={textStyle}>
        <Text variant="hero" style={{ color: '#fff', marginTop: 40 }}>
          {item.title}
        </Text>
        <Text variant="body" style={{ color: 'rgba(255,255,255,0.78)', marginTop: 14 }}>
          {item.body}
        </Text>
      </Animated.View>
    </View>
  );
}

function Dot({ k, x, width }: { k: number; x: SharedValue<number>; width: number }) {
  const style = useAnimatedStyle(() => {
    const d = Math.abs(x.value / width - k);
    return { width: interpolate(d, [0, 1], [34, 10], Extrapolation.CLAMP), opacity: interpolate(d, [0, 1], [1, 0.35], Extrapolation.CLAMP) };
  });
  return <Animated.View style={[styles.dot, style]} />;
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingHorizontal: 24 },
  iconWrap: { width: 110, height: 110, borderRadius: 30, backgroundColor: palette.red, alignItems: 'center', justifyContent: 'center', overflow: 'hidden' },
  iconCut: { position: 'absolute', right: -20, top: -20, bottom: -20, width: 34, backgroundColor: 'rgba(255,255,255,0.18)', transform: [{ skewX: '-16deg' }] },
  bottom: { paddingHorizontal: 24 },
  dots: { flexDirection: 'row', gap: 6, marginBottom: 22 },
  dot: { height: 6, borderRadius: 3, backgroundColor: palette.red, transform: [{ skewX: '-16deg' }] },
});
