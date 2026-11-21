import { useQuery, useQueryClient } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useEffect } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated, { FadeInUp, useAnimatedStyle, useSharedValue, withDelay, withSpring, ZoomIn } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { get } from '@/api/client';
import { Confetti } from '@/motion/Confetti';
import { useSession } from '@/state/session';
import { Button } from '@/ui/Button';
import { Icon } from '@/ui/Icon';
import { haptic } from '@/ui/Press';
import { money } from '@/ui/Price';
import { Text } from '@/ui/Text';
import { spring } from '@/theme/motion';
import { useTheme } from '@/theme/theme';
import { palette, radius } from '@/theme/tokens';

type Status = { number: string; status: string; paid: boolean; total: number };

/**
 * "Order placed!" — confetti, a big red tick that springs in, and the receipt sliding up. While Stripe
 * confirms the payment (a few seconds) it checks the order and switches to "Paid" when it lands.
 */
export default function ConfirmationScreen() {
  const { number } = useLocalSearchParams<{ number: string }>();
  const { c } = useTheme();
  const insets = useSafeAreaInsets();
  const qc = useQueryClient();
  const access = useSession((s) => s.guestOrders[number]);
  const signedIn = !!useSession((s) => s.token);

  const status = useQuery({
    queryKey: ['order-status', number],
    queryFn: () => get<Status>(`/orders/${number}/status`, access ? { access } : undefined),
    refetchInterval: (q) => (q.state.data?.paid ? false : 2500),
  });
  const paid = !!status.data?.paid;

  useEffect(() => {
    haptic('success');
    void qc.invalidateQueries({ queryKey: ['cart'] });
    void qc.invalidateQueries({ queryKey: ['orders'] });
  }, [qc]);

  const tick = useSharedValue(0);
  useEffect(() => {
    tick.value = withDelay(250, withSpring(1, spring.pop));
  }, [tick]);
  const tickStyle = useAnimatedStyle(() => ({ transform: [{ scale: tick.value }, { rotate: `${(1 - tick.value) * -90}deg` }] }));

  return (
    <View style={{ flex: 1, backgroundColor: c.bg, paddingTop: insets.top + 40, paddingHorizontal: 24 }}>
      <Confetti />
      <View style={{ alignItems: 'center' }}>
        <Animated.View style={[styles.tick, tickStyle]}>
          <Icon name="check" size={48} color="#fff" strokeWidth={3} />
        </Animated.View>
        <Animated.View entering={FadeInUp.delay(350).springify()}>
          <Text variant="h1" center style={{ marginTop: 22 }}>
            Order placed!
          </Text>
          <Text variant="body" tone="muted" center style={{ marginTop: 6 }}>
            Thanks for shopping with Panmotive. We've emailed your receipt.
          </Text>
        </Animated.View>
      </View>

      <Animated.View entering={FadeInUp.delay(550).springify().damping(16)} style={[styles.receipt, { backgroundColor: c.surface, borderColor: c.line }]}>
        <View style={styles.row}>
          <Text variant="small" tone="muted">
            Order
          </Text>
          <Text variant="bodyStrong">{number}</Text>
        </View>
        <View style={styles.row}>
          <Text variant="small" tone="muted">
            Total
          </Text>
          <Text variant="bodyStrong">{status.data ? money(status.data.total) : '—'}</Text>
        </View>
        <View style={styles.row}>
          <Text variant="small" tone="muted">
            Payment
          </Text>
          <Animated.View key={String(paid)} entering={ZoomIn.springify()} style={[styles.pill, { backgroundColor: paid ? c.successBg : c.surface2 }]}>
            <Text variant="smallStrong" style={{ color: paid ? c.success : c.muted, fontSize: 12 }}>
              {paid ? 'Paid' : 'Confirming…'}
            </Text>
          </Animated.View>
        </View>
        <View style={[styles.note, { backgroundColor: c.surface2 }]}>
          <Icon name="shield" size={16} />
          <Text variant="small" style={{ flex: 1 }}>
            Your payment is held until you receive your parts. We'll notify you when they ship.
          </Text>
        </View>
      </Animated.View>

      <Animated.View entering={FadeInUp.delay(750).springify()} style={{ gap: 10, marginTop: 20 }}>
        <Button title="Track this order" size="lg" icon="truck" onPress={() => router.replace({ pathname: '/order/[number]', params: { number } })} />
        <Button title="Keep shopping" kind="outline" size="lg" onPress={() => router.replace('/')} />
        {!signedIn ? (
          <Button title="Create an account to track orders easily" kind="ghost" onPress={() => router.push('/sign-in')} />
        ) : null}
      </Animated.View>
    </View>
  );
}

const styles = StyleSheet.create({
  tick: {
    width: 96,
    height: 96,
    borderRadius: 48,
    backgroundColor: palette.red,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: palette.red,
    shadowOpacity: 0.5,
    shadowRadius: 24,
    shadowOffset: { width: 0, height: 10 },
    elevation: 12,
  },
  receipt: { marginTop: 28, borderWidth: 1, borderRadius: radius.lg, padding: 16, gap: 12 },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  pill: { borderRadius: 999, paddingHorizontal: 10, paddingVertical: 3 },
  note: { flexDirection: 'row', gap: 8, borderRadius: radius.md, padding: 10, alignItems: 'center' },
});
