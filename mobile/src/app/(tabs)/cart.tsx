import { useMutation } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router } from 'expo-router';
import { useEffect } from 'react';
import { RefreshControl, ScrollView, StyleSheet, View } from 'react-native';
import { Gesture, GestureDetector } from 'react-native-gesture-handler';
import Animated, {
  FadeIn,
  FadeInDown,
  FadeOutLeft,
  LinearTransition,
  runOnJS,
  useAnimatedStyle,
  useSharedValue,
  withSpring,
  withTiming,
} from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError, api, patch } from '@/api/client';
import type { Cart, CartLine } from '@/api/types';
import { useCart, useSetCart } from '@/state/cart';
import { useGarage } from '@/state/session';
import { Button } from '@/ui/Button';
import { Icon } from '@/ui/Icon';
import { haptic, Press } from '@/ui/Press';
import { money } from '@/ui/Price';
import { Empty, GUTTER } from '@/ui/Section';
import { Skeleton } from '@/ui/Skeleton';
import { TAB_BAR_HEIGHT } from '@/ui/TabBar';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { spring, timing } from '@/theme/motion';
import { useTheme } from '@/theme/theme';
import { font, palette, radius } from '@/theme/tokens';

export default function CartScreen() {
  const { c } = useTheme();
  const insets = useSafeAreaInsets();
  const cart = useCart();
  const vehicle = useGarage((s) => s.vehicle);
  const d = cart.data;

  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <ScrollView
        contentContainerStyle={{ paddingTop: insets.top + 12, paddingBottom: TAB_BAR_HEIGHT + insets.bottom + 140 }}
        refreshControl={<RefreshControl refreshing={cart.isRefetching} onRefresh={() => void cart.refetch()} tintColor={palette.red} />}>
        <View style={{ paddingHorizontal: GUTTER }}>
          <Text variant="h1">Your cart</Text>
          {d && d.count > 0 ? (
            <Text variant="small" tone="muted">
              {d.count} {d.count === 1 ? 'item' : 'items'}
            </Text>
          ) : null}
        </View>

        {cart.isLoading ? (
          <View style={{ padding: GUTTER, gap: 12 }}>
            <Skeleton height={110} radius={radius.lg} />
            <Skeleton height={110} radius={radius.lg} />
          </View>
        ) : !d || d.count === 0 ? (
          <Empty icon="cart" title="Your cart is empty" body="Find the right part for your vehicle and it'll show up here.">
            <Button title="Browse parts" onPress={() => router.navigate('/shop')} />
          </Empty>
        ) : (
          <>
            <FreeShippingBar cart={d} />
            {d.fit.vehicle && d.fit.total > 0 ? (
              <Animated.View entering={FadeIn} style={[styles.fit, { backgroundColor: d.fit.covered === d.fit.total ? c.successBg : c.surface2 }]}>
                <Icon name={d.fit.covered === d.fit.total ? 'shield' : 'vehicle'} size={18} color={d.fit.covered === d.fit.total ? c.success : c.fg} />
                <Text variant="small" style={{ flex: 1, color: d.fit.covered === d.fit.total ? c.success : c.fg }}>
                  {d.fit.covered === d.fit.total
                    ? `Everything fits your ${d.fit.vehicle} — covered by the Fit Guarantee.`
                    : `${d.fit.covered} of ${d.fit.total} items are listed for your ${d.fit.vehicle}.`}
                </Text>
              </Animated.View>
            ) : !vehicle ? (
              <Press onPress={() => router.push('/garage')} style={[styles.fit, { backgroundColor: c.surface2 }]}>
                <Icon name="vehicle" size={18} />
                <Text variant="small" style={{ flex: 1 }}>
                  Add your vehicle to check everything fits.
                </Text>
                <Icon name="chevron-right" size={16} color={c.muted} />
              </Press>
            ) : null}

            {d.groups.map((g, gi) => (
              <Animated.View key={g.seller?.slug ?? gi} entering={FadeInDown.delay(gi * 60).springify()} layout={LinearTransition.springify()} style={[styles.group, { borderColor: c.line, backgroundColor: c.surface }]}>
                <View style={styles.groupHead}>
                  <Icon name="store" size={16} color={c.muted} />
                  <Text variant="smallStrong" style={{ flex: 1 }}>
                    Sold by {g.seller?.name ?? 'a verified seller'}
                  </Text>
                  <Text variant="small" tone="muted">
                    {g.shipping > 0 ? `Shipping ${money(g.shipping)}` : 'Free shipping'}
                  </Text>
                </View>
                {g.seller?.away ? (
                  <Text variant="small" tone="warning" style={{ paddingHorizontal: 14, paddingBottom: 8 }}>
                    This seller is away and isn't taking orders right now.
                  </Text>
                ) : null}
                {g.lines.map((l) => (
                  <Line key={`${l.product_id}-${l.variant_id ?? 0}`} line={l} />
                ))}
              </Animated.View>
            ))}

            {d.bundles.length ? (
              <View style={[styles.saving, { backgroundColor: c.successBg }]}>
                <Icon name="sparkles" size={18} color={c.success} />
                <Text variant="small" style={{ color: c.success, flex: 1 }}>
                  Bundle savings: {d.bundles.map((b) => b.title).join(', ')} (−{money(d.bundle_savings)})
                </Text>
              </View>
            ) : null}
            {d.blocked.length ? (
              <Text variant="small" tone="error" style={{ paddingHorizontal: GUTTER, marginTop: 10 }}>
                Can't ship to your state: {d.blocked.join(', ')}
              </Text>
            ) : null}
          </>
        )}
      </ScrollView>

      {d && d.count > 0 ? <Summary cart={d} /> : null}
    </View>
  );
}

/** "You're $12.40 away from free shipping" — the bar fills, and turns green when you get there. */
function FreeShippingBar({ cart }: { cart: Cart }) {
  const { c } = useTheme();
  const target = cart.free_threshold;
  const share = cart.shipping_free || target <= 0 ? 1 : Math.min(1, cart.subtotal / target);
  const w = useSharedValue(0);

  useEffect(() => {
    w.value = withTiming(share, timing.slow);
    if (share >= 1) haptic('success');
  }, [share, w]);
  const bar = useAnimatedStyle(() => ({ width: `${w.value * 100}%` }));

  if (target <= 0 && !cart.shipping_free) return null;

  return (
    <View style={[styles.free, { backgroundColor: c.surface, borderColor: c.line }]}>
      <Text variant="smallStrong">
        {share >= 1 ? 'You\'ve got free shipping!' : `You're ${money(Math.max(0, target - cart.subtotal))} away from free shipping`}
      </Text>
      <View style={[styles.track, { backgroundColor: c.surface2 }]}>
        <Animated.View style={[styles.fill, { backgroundColor: share >= 1 ? '#18794E' : palette.red }, bar]} />
      </View>
    </View>
  );
}

/** One cart line. Swipe left to remove (with Undo); +/- changes the quantity. */
function Line({ line: l }: { line: CartLine }) {
  const { c } = useTheme();
  const setCart = useSetCart();
  const x = useSharedValue(0);

  const update = useMutation({
    mutationFn: (quantity: number) => patch<Cart>('/cart/items', { product_id: l.product_id, variant_id: l.variant_id, quantity }),
    onSuccess: setCart,
    onError: (e) => toast(e instanceof ApiError ? e.message : 'Couldn\'t update your cart.', 'error'),
  });
  const remove = useMutation({
    mutationFn: () => api<Cart>('/cart/items', { method: 'DELETE', body: { product_id: l.product_id, variant_id: l.variant_id } }),
    onSuccess: (cart) => {
      setCart(cart);
      toast(`Removed ${l.title.slice(0, 40)}`, 'info');
    },
  });

  const swipe = Gesture.Pan()
    .activeOffsetX([-15, 15])
    .failOffsetY([-10, 10])
    .onUpdate((e) => {
      x.value = Math.min(0, e.translationX);
    })
    .onEnd((e) => {
      if (e.translationX < -110) {
        x.value = withTiming(-500, { duration: 200 });
        runOnJS(haptic)('medium');
        runOnJS(remove.mutate)();
      } else x.value = withSpring(0, spring.gentle);
    });
  const row = useAnimatedStyle(() => ({ transform: [{ translateX: x.value }] }));
  const bin = useAnimatedStyle(() => ({ opacity: Math.min(1, -x.value / 90) }));

  return (
    <Animated.View exiting={FadeOutLeft.duration(200)} layout={LinearTransition.springify()}>
      <Animated.View style={[styles.bin, bin]}>
        <Icon name="trash" size={20} color="#fff" />
      </Animated.View>
      <GestureDetector gesture={swipe}>
        <Animated.View style={[styles.line, { backgroundColor: c.surface, borderTopColor: c.line }, row]}>
          <Press haptic={false} onPress={() => router.push({ pathname: '/product/[slug]', params: { slug: l.slug } })} scaleTo={0.97}>
            <Image source={l.image} style={[styles.img, { backgroundColor: c.surface2 }]} contentFit="cover" />
          </Press>
          <View style={{ flex: 1 }}>
            <Text variant="smallStrong" numberOfLines={2}>
              {l.title}
            </Text>
            {l.variant ? (
              <Text variant="small" tone="muted">
                {l.variant}
              </Text>
            ) : null}
            <View style={styles.lineFoot}>
              <View>
                <Text variant="bodyStrong">{money(l.line_total)}</Text>
                {l.list_unit_price > l.unit_price ? (
                  <Text variant="small" tone="muted" style={{ textDecorationLine: 'line-through', fontSize: 11 }}>
                    {money(l.list_unit_price * l.quantity)}
                  </Text>
                ) : null}
              </View>
              <View style={[styles.stepper, { borderColor: c.line }]}>
                <Press
                  onPress={() => (l.quantity <= 1 ? remove.mutate() : update.mutate(l.quantity - 1))}
                  haptic="selection"
                  style={styles.stepBtn}
                  accessibilityLabel={l.quantity <= 1 ? 'Remove' : 'Fewer'}>
                  <Icon name={l.quantity <= 1 ? 'trash' : 'minus'} size={16} />
                </Press>
                <Animated.Text key={l.quantity} entering={FadeIn} style={{ minWidth: 22, textAlign: 'center', color: c.fg, fontFamily: font.bold }}>
                  {l.quantity}
                </Animated.Text>
                <Press
                  onPress={() => (l.quantity >= l.max ? toast(`Only ${l.max} in stock`, 'info') : update.mutate(l.quantity + 1))}
                  haptic="selection"
                  style={styles.stepBtn}
                  accessibilityLabel="More">
                  <Icon name="plus" size={16} />
                </Press>
              </View>
            </View>
          </View>
        </Animated.View>
      </GestureDetector>
    </Animated.View>
  );
}

function Summary({ cart }: { cart: Cart }) {
  const { c, dark } = useTheme();
  const insets = useSafeAreaInsets();

  return (
    <Animated.View entering={FadeInDown.springify()} style={[styles.summary, { bottom: TAB_BAR_HEIGHT + Math.max(insets.bottom, 10) + 8, backgroundColor: dark ? 'rgba(34,40,48,0.98)' : '#fff', borderColor: c.line }]}>
      <View style={{ flex: 1 }}>
        <Text variant="small" tone="muted">
          Estimated total
        </Text>
        <Animated.Text key={cart.estimated_total} entering={FadeIn} style={{ color: c.fg, fontFamily: font.bold, fontSize: 20 }}>
          {money(cart.estimated_total)}
        </Animated.Text>
        <Text variant="small" tone="muted" style={{ fontSize: 11 }}>
          {cart.shipping > 0 ? `incl. ${money(cart.shipping)} shipping` : 'Free shipping'}
          {cart.tax_note ? ' · tax at checkout' : ''}
        </Text>
      </View>
      <Button title="Checkout" iconRight="arrow-right" size="lg" onPress={() => router.push('/checkout')} />
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  free: { marginHorizontal: GUTTER, marginTop: 16, borderRadius: radius.lg, borderWidth: 1, padding: 14, gap: 10 },
  track: { height: 8, borderRadius: 4, overflow: 'hidden' },
  fill: { height: 8, borderRadius: 4 },
  fit: { marginHorizontal: GUTTER, marginTop: 12, borderRadius: radius.md, padding: 12, flexDirection: 'row', alignItems: 'center', gap: 10 },
  group: { marginHorizontal: GUTTER, marginTop: 14, borderRadius: radius.lg, borderWidth: 1, overflow: 'hidden' },
  groupHead: { flexDirection: 'row', alignItems: 'center', gap: 8, padding: 14 },
  line: { flexDirection: 'row', gap: 12, padding: 14, borderTopWidth: StyleSheet.hairlineWidth },
  bin: { position: 'absolute', right: 0, top: 0, bottom: 0, width: 120, backgroundColor: palette.red, alignItems: 'flex-end', justifyContent: 'center', paddingRight: 24 },
  img: { width: 76, height: 76, borderRadius: 12 },
  lineFoot: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 8 },
  stepper: { flexDirection: 'row', alignItems: 'center', borderWidth: 1, borderRadius: radius.pill },
  stepBtn: { width: 34, height: 34, alignItems: 'center', justifyContent: 'center' },
  saving: { marginHorizontal: GUTTER, marginTop: 12, borderRadius: radius.md, padding: 12, flexDirection: 'row', gap: 8, alignItems: 'center' },
  summary: {
    position: 'absolute',
    left: 12,
    right: 12,
    borderRadius: 20,
    borderWidth: 1,
    padding: 14,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    shadowColor: '#000',
    shadowOpacity: 0.3,
    shadowRadius: 20,
    shadowOffset: { width: 0, height: 8 },
    elevation: 10,
  },
});
