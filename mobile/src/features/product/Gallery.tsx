import { Image } from 'expo-image';
import { useEffect, useRef, useState } from 'react';
import { FlatList, Modal, StyleSheet, View, useWindowDimensions } from 'react-native';
import { Gesture, GestureDetector, GestureHandlerRootView } from 'react-native-gesture-handler';
import Animated, { type SharedValue, Extrapolation, interpolate, runOnJS, useAnimatedStyle, useSharedValue, withSpring, withTiming } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Icon } from '@/ui/Icon';
import { Press } from '@/ui/Press';
import { Text } from '@/ui/Text';
import { spring } from '@/theme/motion';
import { useTheme } from '@/theme/theme';

type Photo = { id: number | string; thumb: string; large: string };

/**
 * The product photos: swipe through them full width; the top photo drifts and grows as the page is
 * pulled down (parallax via `scrollY`). Tap to open full screen, where you can pinch to zoom and drag
 * down to close.
 */
export function Gallery({ photos, scrollY, index, onIndex }: { photos: Photo[]; scrollY: SharedValue<number>; index: number; onIndex: (i: number) => void }) {
  const { width } = useWindowDimensions();
  const { c } = useTheme();
  const [viewer, setViewer] = useState(false);
  const list = photos.length ? photos : [{ id: 'none', thumb: '', large: '' }];
  const listRef = useRef<FlatList<Photo>>(null);

  // Picking an option with its own photo (a colour, a side) slides the gallery to that photo.
  useEffect(() => {
    if (index >= 0 && index < list.length) listRef.current?.scrollToIndex({ index, animated: true });
  }, [index, list.length]);

  const parallax = useAnimatedStyle(() => ({
    transform: [
      { translateY: interpolate(scrollY.value, [-200, 0, width], [-100, 0, width * 0.45], Extrapolation.CLAMP) },
      { scale: interpolate(scrollY.value, [-200, 0], [1.5, 1], Extrapolation.CLAMP) },
    ],
  }));

  return (
    <View style={{ width, height: width, overflow: 'hidden', backgroundColor: c.surface2 }}>
      <Animated.View style={[StyleSheet.absoluteFill, parallax]}>
        <FlatList
          ref={listRef}
          data={list}
          horizontal
          pagingEnabled
          getItemLayout={(_, idx) => ({ length: width, offset: width * idx, index: idx })}
          showsHorizontalScrollIndicator={false}
          keyExtractor={(p) => String(p.id)}
          onMomentumScrollEnd={(e) => onIndex(Math.round(e.nativeEvent.contentOffset.x / width))}
          renderItem={({ item }) => (
            <Press haptic={false} scaleTo={1} onPress={() => item.large && setViewer(true)} accessibilityLabel="Open photo full screen">
              {item.large ? (
                <Image source={item.large} placeholder={item.thumb} style={{ width, height: width }} contentFit="cover" transition={200} />
              ) : (
                <View style={{ width, height: width, alignItems: 'center', justifyContent: 'center' }}>
                  <Icon name="image" size={48} color={c.muted} />
                </View>
              )}
            </Press>
          )}
        />
      </Animated.View>
      {list.length > 1 && (
        <View style={styles.dots} pointerEvents="none">
          {list.map((p, i) => (
            <View key={p.id} style={[styles.dot, i === index && styles.dotOn]} />
          ))}
        </View>
      )}
      <Viewer photos={list} start={index} open={viewer} onClose={() => setViewer(false)} />
    </View>
  );
}

function Viewer({ photos, start, open, onClose }: { photos: Photo[]; start: number; open: boolean; onClose: () => void }) {
  const { width, height } = useWindowDimensions();
  const insets = useSafeAreaInsets();
  const [i, setI] = useState(start);
  const drag = useSharedValue(0);

  const close = () => {
    drag.value = 0;
    onClose();
  };

  const dismiss = Gesture.Pan()
    .activeOffsetY([-12, 12])
    .onUpdate((e) => {
      drag.value = e.translationY;
    })
    .onEnd((e) => {
      if (Math.abs(e.translationY) > 140 || Math.abs(e.velocityY) > 900) {
        drag.value = withTiming(Math.sign(e.translationY) * height, { duration: 200 }, () => runOnJS(close)());
      } else {
        drag.value = withSpring(0, spring.gentle);
      }
    });

  const bg = useAnimatedStyle(() => ({ opacity: interpolate(Math.abs(drag.value), [0, 300], [1, 0.3], Extrapolation.CLAMP) }));
  const sheet = useAnimatedStyle(() => ({ transform: [{ translateY: drag.value }, { scale: interpolate(Math.abs(drag.value), [0, 300], [1, 0.9], Extrapolation.CLAMP) }] }));

  return (
    <Modal visible={open} transparent animationType="fade" onRequestClose={close} statusBarTranslucent>
      <GestureHandlerRootView style={{ flex: 1 }}>
        <Animated.View style={[StyleSheet.absoluteFill, { backgroundColor: '#000' }, bg]} />
        <GestureDetector gesture={dismiss}>
          <Animated.View style={[{ flex: 1, justifyContent: 'center' }, sheet]}>
            <FlatList
              data={photos}
              horizontal
              pagingEnabled
              initialScrollIndex={start}
              getItemLayout={(_, idx) => ({ length: width, offset: width * idx, index: idx })}
              showsHorizontalScrollIndicator={false}
              keyExtractor={(p) => String(p.id)}
              onMomentumScrollEnd={(e) => setI(Math.round(e.nativeEvent.contentOffset.x / width))}
              renderItem={({ item }) => <ZoomImage uri={item.large} width={width} height={height} />}
            />
          </Animated.View>
        </GestureDetector>
        <View style={[styles.viewerTop, { top: insets.top + 8 }]}>
          <Text variant="smallStrong" style={{ color: '#fff' }}>
            {i + 1} / {photos.length}
          </Text>
          <Press onPress={close} style={styles.close} accessibilityLabel="Close photos">
            <Icon name="x" size={22} color="#fff" />
          </Press>
        </View>
      </GestureHandlerRootView>
    </Modal>
  );
}

/** Pinch to zoom (and pan while zoomed); double-tap toggles 2.5x. */
function ZoomImage({ uri, width, height }: { uri: string; width: number; height: number }) {
  const scale = useSharedValue(1);
  const base = useSharedValue(1);
  const tx = useSharedValue(0);
  const ty = useSharedValue(0);

  const pinch = Gesture.Pinch()
    .onUpdate((e) => {
      scale.value = Math.max(1, Math.min(5, base.value * e.scale));
    })
    .onEnd(() => {
      base.value = scale.value;
      if (scale.value <= 1.02) {
        scale.value = withSpring(1);
        base.value = 1;
        tx.value = withSpring(0);
        ty.value = withSpring(0);
      }
    });
  const pan = Gesture.Pan()
    .minPointers(1)
    .manualActivation(false)
    .onUpdate((e) => {
      if (scale.value > 1) {
        tx.value = e.translationX;
        ty.value = e.translationY;
      }
    })
    .onEnd(() => {
      tx.value = withSpring(0);
      ty.value = withSpring(0);
    })
    .enabled(true);
  const doubleTap = Gesture.Tap()
    .numberOfTaps(2)
    .onEnd(() => {
      const next = scale.value > 1 ? 1 : 2.5;
      scale.value = withSpring(next, spring.gentle);
      base.value = next;
    });

  const style = useAnimatedStyle(() => ({ transform: [{ translateX: tx.value }, { translateY: ty.value }, { scale: scale.value }] }));

  return (
    <GestureDetector gesture={Gesture.Simultaneous(pinch, doubleTap, Gesture.Race(pan))}>
      <Animated.View style={[{ width, height, justifyContent: 'center' }, style]}>
        <Image source={uri} style={{ width, height: width * 1.2 }} contentFit="contain" />
      </Animated.View>
    </GestureDetector>
  );
}

const styles = StyleSheet.create({
  dots: { position: 'absolute', bottom: 14, alignSelf: 'center', flexDirection: 'row', gap: 6, backgroundColor: 'rgba(0,0,0,0.35)', borderRadius: 999, padding: 6 },
  dot: { width: 6, height: 6, borderRadius: 3, backgroundColor: 'rgba(255,255,255,0.45)' },
  dotOn: { width: 18, backgroundColor: '#fff' },
  viewerTop: { position: 'absolute', left: 16, right: 16, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  close: { width: 40, height: 40, borderRadius: 20, backgroundColor: 'rgba(255,255,255,0.15)', alignItems: 'center', justifyContent: 'center' },
});
