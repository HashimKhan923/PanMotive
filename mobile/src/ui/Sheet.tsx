import { useEffect, useState, type ReactNode } from 'react';
import { Modal, Pressable, StyleSheet, View, useWindowDimensions } from 'react-native';
import { Gesture, GestureDetector, GestureHandlerRootView } from 'react-native-gesture-handler';
import Animated, { Extrapolation, interpolate, runOnJS, useAnimatedStyle, useSharedValue, withSpring, withTiming } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Text } from '@/ui/Text';
import { spring } from '@/theme/motion';
import { useTheme } from '@/theme/theme';

type Props = { open: boolean; onClose: () => void; title?: string; children: ReactNode; footer?: ReactNode; maxHeight?: number };

/**
 * A bottom sheet: springs up over a dimmed backdrop, drag the handle (or flick) down to close.
 * Used for filters, sorting, options and quick forms.
 */
export function Sheet({ open, onClose, title, children, footer, maxHeight }: Props) {
  const { c } = useTheme();
  const { height } = useWindowDimensions();
  const insets = useSafeAreaInsets();
  const [mounted, setMounted] = useState(open);
  const y = useSharedValue(height);
  const limit = maxHeight ?? height * 0.86;

  useEffect(() => {
    if (open) {
      setMounted(true);
      y.value = height;
      y.value = withSpring(0, spring.gentle);
    } else if (mounted) {
      y.value = withTiming(height, { duration: 240 }, (d) => d && runOnJS(setMounted)(false));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const drag = Gesture.Pan()
    .onUpdate((e) => {
      y.value = Math.max(0, e.translationY);
    })
    .onEnd((e) => {
      if (e.translationY > 120 || e.velocityY > 800) runOnJS(onClose)();
      else y.value = withSpring(0, spring.gentle);
    });

  const sheet = useAnimatedStyle(() => ({ transform: [{ translateY: y.value }] }));
  const backdrop = useAnimatedStyle(() => ({ opacity: interpolate(y.value, [0, height * 0.6], [1, 0], Extrapolation.CLAMP) }));

  if (!mounted) return null;

  return (
    <Modal transparent visible onRequestClose={onClose} statusBarTranslucent animationType="none">
      <GestureHandlerRootView style={{ flex: 1 }}>
        <Animated.View style={[StyleSheet.absoluteFill, { backgroundColor: c.overlay }, backdrop]}>
          <Pressable style={StyleSheet.absoluteFill} onPress={onClose} accessibilityLabel="Close" />
        </Animated.View>
        <Animated.View style={[styles.sheet, { backgroundColor: c.bg, maxHeight: limit, paddingBottom: footer ? 0 : insets.bottom + 12 }, sheet]}>
          <GestureDetector gesture={drag}>
            <View style={styles.head}>
              <View style={[styles.handle, { backgroundColor: c.line }]} />
              {title ? (
                <Text variant="h3" style={{ marginTop: 10 }}>
                  {title}
                </Text>
              ) : null}
            </View>
          </GestureDetector>
          <View style={{ flexShrink: 1 }}>{children}</View>
          {footer ? <View style={[styles.footer, { borderTopColor: c.line, paddingBottom: insets.bottom + 12 }]}>{footer}</View> : null}
        </Animated.View>
      </GestureHandlerRootView>
    </Modal>
  );
}

const styles = StyleSheet.create({
  sheet: { position: 'absolute', left: 0, right: 0, bottom: 0, borderTopLeftRadius: 24, borderTopRightRadius: 24, overflow: 'hidden' },
  head: { alignItems: 'center', paddingTop: 10, paddingBottom: 12, paddingHorizontal: 16 },
  handle: { width: 40, height: 5, borderRadius: 3 },
  footer: { borderTopWidth: StyleSheet.hairlineWidth, padding: 16, flexDirection: 'row', gap: 10 },
});
