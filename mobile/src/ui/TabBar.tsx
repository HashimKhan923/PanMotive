import type { BottomTabBarProps } from 'expo-router/build/layouts/Tabs';
import { BlurView } from 'expo-blur';
import { useEffect, useState } from 'react';
import { Platform, StyleSheet, View, type LayoutChangeEvent } from 'react-native';
import Animated, { useAnimatedStyle, useSharedValue, withSequence, withSpring } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useCartCount } from '@/state/cart';
import { Icon, type IconName } from '@/ui/Icon';
import { haptic, Press } from '@/ui/Press';
import { Text } from '@/ui/Text';
import { spring } from '@/theme/motion';
import { useTheme } from '@/theme/theme';
import { palette } from '@/theme/tokens';

const TABS: Record<string, { icon: IconName; label: string }> = {
  index: { icon: 'grid', label: 'Home' },
  search: { icon: 'search', label: 'Search' },
  deals: { icon: 'tag', label: 'Deals' },
  cart: { icon: 'cart', label: 'Cart' },
  account: { icon: 'user', label: 'Account' },
};

export const TAB_BAR_HEIGHT = 64;

/** Where the cart tab sits on screen — the add-to-cart flight lands here. */
export const cartTabTarget = { x: 0, y: 0 };

/**
 * The floating tab bar. A red pill slides under the active tab on a spring, the active icon lifts,
 * the cart badge pops when something is added. Blurred glass over the content behind it.
 */
export function TabBar({ state, emitter, navigateToTab }: BottomTabBarProps) {
  const { c, dark } = useTheme();
  const insets = useSafeAreaInsets();
  const [w, setW] = useState(0);
  const count = useCartCount();
  const tabW = w / state.routes.length;

  const pill = useSharedValue(state.index);
  useEffect(() => {
    pill.value = withSpring(state.index, spring.gentle);
  }, [pill, state.index]);
  const pillStyle = useAnimatedStyle(() => ({ transform: [{ translateX: pill.value * tabW + 8 }, { skewX: '-8deg' }] }));

  const onLayout = (e: LayoutChangeEvent) => {
    setW(e.nativeEvent.layout.width);
    const { x, y, width } = e.nativeEvent.layout;
    const cartIndex = state.routes.findIndex((r) => r.name === 'cart');
    cartTabTarget.x = x + (width / state.routes.length) * (cartIndex + 0.5);
    cartTabTarget.y = y;
  };

  return (
    <View style={[styles.wrap, { paddingBottom: Math.max(insets.bottom, 10) }]} pointerEvents="box-none">
      <View onLayout={onLayout} style={[styles.bar, { borderColor: c.line, shadowColor: c.shadow }]}>
        {Platform.OS === 'android' ? (
          <View style={[StyleSheet.absoluteFill, { backgroundColor: c.tabBar }]} />
        ) : (
          <BlurView intensity={60} tint={dark ? 'dark' : 'light'} style={[StyleSheet.absoluteFill, { backgroundColor: c.tabBar }]} />
        )}
        {w > 0 && <Animated.View style={[styles.pill, { width: tabW - 16, backgroundColor: palette.red }, pillStyle]} />}
        {state.routes.map((route, i) => {
          const meta = TABS[route.name] ?? { icon: 'box', label: route.name };
          const focused = state.index === i;

          return (
            <Press
              key={route.key}
              haptic={false}
              scaleTo={0.9}
              accessibilityRole="tab"
              accessibilityState={{ selected: focused }}
              accessibilityLabel={meta.label + (route.name === 'cart' && count ? `, ${count} items` : '')}
              onPress={() => {
                haptic('selection');
                const event = emitter.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
                if (!focused && !event.defaultPrevented) navigateToTab(route.key);
              }}
              style={styles.tab}>
              <TabIcon icon={meta.icon} focused={focused} badge={route.name === 'cart' ? count : 0} />
              <Text variant="smallStrong" style={{ fontSize: 10.5, lineHeight: 13, color: focused ? '#fff' : c.muted }}>
                {meta.label}
              </Text>
            </Press>
          );
        })}
      </View>
    </View>
  );
}

function TabIcon({ icon, focused, badge }: { icon: IconName; focused: boolean; badge: number }) {
  const { c } = useTheme();
  const lift = useSharedValue(focused ? 1 : 0);
  const pop = useSharedValue(1);
  const [last, setLast] = useState(badge);

  useEffect(() => {
    lift.value = withSpring(focused ? 1 : 0, spring.snappy);
  }, [focused, lift]);

  useEffect(() => {
    if (badge > last) pop.value = withSequence(withSpring(1.45, spring.pop), withSpring(1, spring.pop));
    setLast(badge);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [badge]);

  const iconStyle = useAnimatedStyle(() => ({ transform: [{ translateY: -lift.value * 2 }, { scale: 1 + lift.value * 0.06 }] }));
  const badgeStyle = useAnimatedStyle(() => ({ transform: [{ scale: pop.value }] }));

  return (
    <Animated.View style={iconStyle}>
      <Icon name={icon} size={22} color={focused ? '#fff' : c.muted} />
      {badge > 0 && (
        <Animated.View style={[styles.badge, { backgroundColor: focused ? '#fff' : palette.red, borderColor: c.bg }, badgeStyle]}>
          <Text variant="smallStrong" style={{ fontSize: 10, lineHeight: 12, color: focused ? palette.red : '#fff' }}>
            {badge > 99 ? '99+' : badge}
          </Text>
        </Animated.View>
      )}
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  wrap: { position: 'absolute', left: 0, right: 0, bottom: 0, paddingHorizontal: 12 },
  bar: {
    height: TAB_BAR_HEIGHT,
    borderRadius: 22,
    borderWidth: StyleSheet.hairlineWidth,
    flexDirection: 'row',
    overflow: 'hidden',
    shadowOpacity: 0.35,
    shadowRadius: 24,
    shadowOffset: { width: 0, height: 10 },
    elevation: 12,
  },
  pill: { position: 'absolute', top: 8, bottom: 8, left: 0, borderRadius: 14 },
  tab: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 3 },
  badge: {
    position: 'absolute',
    top: -6,
    right: -12,
    minWidth: 18,
    height: 18,
    borderRadius: 9,
    paddingHorizontal: 4,
    borderWidth: 2,
    alignItems: 'center',
    justifyContent: 'center',
  },
});
