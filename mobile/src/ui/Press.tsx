import * as Haptics from 'expo-haptics';
import { type ReactNode } from 'react';
import { Platform, Pressable, type PressableProps, type StyleProp, type ViewStyle } from 'react-native';
import Animated, { useAnimatedStyle, useSharedValue, withSpring } from 'react-native-reanimated';

import { spring } from '@/theme/motion';

const AnimatedPressable = Animated.createAnimatedComponent(Pressable);

type Props = Omit<PressableProps, 'style'> & {
  style?: StyleProp<ViewStyle>;
  children?: ReactNode;
  /** How far it shrinks under the finger (0.96 default; cards 0.98). */
  scaleTo?: number;
  haptic?: 'light' | 'medium' | 'selection' | false;
};

export const haptic = (kind: 'light' | 'medium' | 'heavy' | 'selection' | 'success' | 'warning' | 'error') => {
  if (Platform.OS === 'web') return;
  if (kind === 'selection') return void Haptics.selectionAsync();
  if (kind === 'success' || kind === 'warning' || kind === 'error') {
    return void Haptics.notificationAsync(
      { success: Haptics.NotificationFeedbackType.Success, warning: Haptics.NotificationFeedbackType.Warning, error: Haptics.NotificationFeedbackType.Error }[kind],
    );
  }
  void Haptics.impactAsync({ light: Haptics.ImpactFeedbackStyle.Light, medium: Haptics.ImpactFeedbackStyle.Medium, heavy: Haptics.ImpactFeedbackStyle.Heavy }[kind]);
};

/**
 * Everything tappable: springs down under the finger and back up, with a light tap of haptics.
 * The touch area is never smaller than 44 x 44 (hitSlop), like the website's .pm-tap.
 */
export function Press({ style, children, scaleTo = 0.96, haptic: kind = 'light', onPressIn, onPressOut, onPress, hitSlop = 8, ...rest }: Props) {
  const scale = useSharedValue(1);
  const animated = useAnimatedStyle(() => ({ transform: [{ scale: scale.value }] }));

  return (
    <AnimatedPressable
      {...rest}
      hitSlop={hitSlop}
      accessibilityRole={rest.accessibilityRole ?? 'button'}
      onPressIn={(e) => {
        scale.value = withSpring(scaleTo, spring.snappy);
        onPressIn?.(e);
      }}
      onPressOut={(e) => {
        scale.value = withSpring(1, spring.snappy);
        onPressOut?.(e);
      }}
      onPress={(e) => {
        if (kind) haptic(kind);
        onPress?.(e);
      }}
      style={[style, animated]}>
      {children}
    </AnimatedPressable>
  );
}
