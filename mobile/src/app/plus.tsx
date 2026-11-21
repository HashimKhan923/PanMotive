import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LinearGradient } from 'expo-linear-gradient';
import { router } from 'expo-router';
import { Alert, StyleSheet, View } from 'react-native';
import Animated, { FadeInDown, FadeInRight } from 'react-native-reanimated';

import { ApiError, get, post } from '@/api/client';
import { Confetti } from '@/motion/Confetti';
import { useSession } from '@/state/session';
import { Button } from '@/ui/Button';
import { FormScreen } from '@/ui/FormScreen';
import { Icon } from '@/ui/Icon';
import { Logo } from '@/ui/Logo';
import { money } from '@/ui/Price';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { useTheme } from '@/theme/theme';
import { radius } from '@/theme/tokens';

type Plus = { price: number; term_months: number; perks: string[]; active: boolean; until: string | null; auto_renew: boolean; wallet_balance: number | null };

/** Panmotive Plus: free shipping on every order and extra cashback. Paid from wallet credit. */
export default function PlusScreen() {
  const { c } = useTheme();
  const qc = useQueryClient();
  const signedIn = !!useSession((s) => s.token);
  const q = useQuery({ queryKey: ['plus'], queryFn: () => get<Plus>('/plus') });
  const p = q.data;

  const join = useMutation({
    mutationFn: () => post<{ message: string }>('/plus/join', { auto_renew: true }),
    onSuccess: (r) => {
      toast(r.message);
      void qc.invalidateQueries();
    },
    onError: (e) => toast(e instanceof ApiError ? e.message : 'Couldn\'t join right now.', 'error'),
  });
  const cancel = useMutation({
    mutationFn: () => post<{ message: string }>('/plus/cancel'),
    onSuccess: (r) => {
      toast(r.message, 'info');
      void qc.invalidateQueries({ queryKey: ['plus'] });
    },
  });

  const perks = p?.perks.length ? p.perks : ['Free shipping on every order, no minimum', '2% extra cashback on all purchases', 'Members-only pricing and early deal access', 'Priority support'];

  return (
    <View style={{ flex: 1 }}>
      <FormScreen
        title="Panmotive Plus"
        footer={
          p?.active ? (
            p.auto_renew ? (
              <Button
                title="Turn off auto-renew"
                kind="outline"
                block
                onPress={() => Alert.alert('Turn off auto-renew?', 'Your membership stays active until it ends.', [{ text: 'Keep it', style: 'cancel' }, { text: 'Turn off', style: 'destructive', onPress: () => cancel.mutate() }])}
              />
            ) : null
          ) : (
            <Button title={signedIn ? `Join for ${money(p?.price ?? 0)}/year` : 'Sign in to join'} size="lg" block loading={join.isPending} onPress={() => (signedIn ? join.mutate() : router.push('/sign-in'))} />
          )
        }>
        <Animated.View entering={FadeInDown.springify()} style={styles.hero}>
          <LinearGradient colors={['#15191E', '#2B323C']} style={StyleSheet.absoluteFill} />
          <View style={styles.cut} />
          <Logo variant="symbol" height={36} />
          <Text variant="h1" style={{ color: '#fff', marginTop: 14 }}>
            {p?.active ? 'You\'re a Plus member' : 'Free shipping. Every order.'}
          </Text>
          <Text variant="body" style={{ color: 'rgba(255,255,255,0.8)', marginTop: 4 }}>
            {p?.active && p.until
              ? `Active through ${new Date(p.until).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}${p.auto_renew ? ', renews automatically' : ''}.`
              : `${money(p?.price ?? 59)}/year, billed from your Panmotive wallet.`}
          </Text>
        </Animated.View>
        {perks.map((perk, i) => (
          <Animated.View key={perk} entering={FadeInRight.delay(120 + i * 80).springify()} style={[styles.perk, { backgroundColor: c.surface, borderColor: c.line }]}>
            <View style={styles.tick}>
              <Icon name="check" size={16} color="#fff" strokeWidth={3} />
            </View>
            <Text variant="bodyStrong" style={{ flex: 1 }}>
              {perk}
            </Text>
          </Animated.View>
        ))}
        {!p?.active && p?.wallet_balance != null ? (
          <Text variant="small" tone="muted">
            Your wallet: {money(p.wallet_balance)}. Membership fees are non-refundable.
          </Text>
        ) : null}
      </FormScreen>
      {join.isSuccess ? <Confetti /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  hero: { borderRadius: radius.xl, padding: 22, overflow: 'hidden' },
  cut: { position: 'absolute', right: -30, top: -30, bottom: -30, width: 70, backgroundColor: '#E51B2B', transform: [{ skewX: '-16deg' }] },
  perk: { flexDirection: 'row', alignItems: 'center', gap: 12, borderWidth: 1, borderRadius: radius.lg, padding: 14 },
  tick: { width: 28, height: 28, borderRadius: 14, backgroundColor: '#E51B2B', alignItems: 'center', justifyContent: 'center' },
});
