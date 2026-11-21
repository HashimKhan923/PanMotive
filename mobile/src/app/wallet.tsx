import { useQuery } from '@tanstack/react-query';
import * as Clipboard from 'expo-clipboard';
import { LinearGradient } from 'expo-linear-gradient';
import { useEffect, useState } from 'react';
import { FlatList, Share, StyleSheet, View } from 'react-native';
import Animated, { FadeInDown } from 'react-native-reanimated';

import { get } from '@/api/client';
import { Button } from '@/ui/Button';
import { FormScreen } from '@/ui/FormScreen';
import { Icon } from '@/ui/Icon';
import { haptic } from '@/ui/Press';
import { money } from '@/ui/Price';
import { Skeleton } from '@/ui/Skeleton';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { useTheme } from '@/theme/theme';
import { radius } from '@/theme/tokens';

type Wallet = { balance: number; referral_code: string; referral_link: string; transactions: { type: string; amount: number; balance_after: number; description: string; at: string }[] };

/** The wallet: the balance counts up, cashback and credits listed, and your invite link. */
export default function WalletScreen() {
  const { c } = useTheme();
  const q = useQuery({ queryKey: ['wallet'], queryFn: () => get<Wallet>('/wallet') });
  const w = q.data;
  const shown = useCountUp(w?.balance ?? 0);

  return (
    <FormScreen title="Wallet & cashback">
      {!w ? (
        <Skeleton height={160} radius={radius.xl} />
      ) : (
        <>
          <Animated.View entering={FadeInDown.springify()} style={styles.card}>
            <LinearGradient colors={['#BB1220', '#E51B2B']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={StyleSheet.absoluteFill} />
            <View style={styles.cut} />
            <Text variant="tiny" style={{ color: 'rgba(255,255,255,0.85)' }}>
              Available credit
            </Text>
            <Text style={{ color: '#fff', fontFamily: 'Inter_700Bold', fontSize: 44, letterSpacing: -1.5, marginTop: 6 }}>{money(shown)}</Text>
            <Text variant="small" style={{ color: 'rgba(255,255,255,0.85)' }}>
              Use it at checkout — up to half of the parts in an order.
            </Text>
          </Animated.View>

          <View style={[styles.invite, { borderColor: c.line, backgroundColor: c.surface }]}>
            <Text variant="h3">Invite a friend</Text>
            <Text variant="small" tone="muted">
              Share your code. You both get credit when they place their first order.
            </Text>
            <View style={{ flexDirection: 'row', gap: 10, marginTop: 10 }}>
              <Button
                title={w.referral_code}
                kind="outline"
                icon="copy"
                style={{ flex: 1 }}
                onPress={async () => {
                  await Clipboard.setStringAsync(w.referral_code);
                  haptic('success');
                  toast('Code copied');
                }}
              />
              <Button title="Share" icon="external" onPress={() => void Share.share({ message: `Get parts that fit on Panmotive: ${w.referral_link}` })} />
            </View>
          </View>

          <Text variant="h3" style={{ marginTop: 8 }}>
            History
          </Text>
          <FlatList
            scrollEnabled={false}
            data={w.transactions}
            keyExtractor={(_, i) => String(i)}
            ListEmptyComponent={
              <Text variant="small" tone="muted">
                Cashback from delivered orders shows up here.
              </Text>
            }
            renderItem={({ item: t, index }) => (
              <Animated.View entering={FadeInDown.delay(index * 40)} style={[styles.tx, { borderBottomColor: c.line }]}>
                <View style={[styles.txIcon, { backgroundColor: t.amount >= 0 ? c.successBg : c.surface2 }]}>
                  <Icon name={t.amount >= 0 ? 'plus' : 'minus'} size={16} color={t.amount >= 0 ? c.success : c.fg} />
                </View>
                <View style={{ flex: 1 }}>
                  <Text variant="smallStrong" numberOfLines={1}>
                    {t.description}
                  </Text>
                  <Text variant="small" tone="muted" style={{ fontSize: 11 }}>
                    {new Date(t.at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                  </Text>
                </View>
                <Text variant="bodyStrong" tone={t.amount >= 0 ? 'success' : 'fg'}>
                  {t.amount >= 0 ? '+' : '−'}
                  {money(Math.abs(t.amount))}
                </Text>
              </Animated.View>
            )}
          />
        </>
      )}
    </FormScreen>
  );
}

/** Counts a number up from 0 over ~0.9 s (eased). */
export function useCountUp(target: number) {
  const [v, setV] = useState(0);
  useEffect(() => {
    let raf = 0;
    const start = Date.now();
    const tick = () => {
      const p = Math.min(1, (Date.now() - start) / 900);
      setV(target * (1 - Math.pow(1 - p, 3)));
      if (p < 1) raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf);
  }, [target]);
  return v;
}

const styles = StyleSheet.create({
  card: { borderRadius: radius.xl, padding: 22, overflow: 'hidden', minHeight: 170, justifyContent: 'flex-end' },
  cut: { position: 'absolute', right: 30, top: -30, bottom: -30, width: 8, backgroundColor: 'rgba(255,255,255,0.25)', transform: [{ skewX: '-16deg' }] },
  invite: { borderWidth: 1, borderRadius: radius.lg, padding: 16, gap: 4 },
  tx: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12, borderBottomWidth: StyleSheet.hairlineWidth },
  txIcon: { width: 34, height: 34, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
});

