import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router } from 'expo-router';
import { Alert, StyleSheet, View } from 'react-native';
import Animated, { FadeInDown, LinearTransition } from 'react-native-reanimated';

import { ApiError, get, patch } from '@/api/client';
import { Button } from '@/ui/Button';
import { FormScreen } from '@/ui/FormScreen';
import { Empty } from '@/ui/Section';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { useTheme } from '@/theme/theme';
import { radius } from '@/theme/tokens';

type Sub = { id: number; status: string; quantity: number; interval: string; discount_pct: number; next_run_at: string | null; orders_count: number; product: { title: string; slug: string; image: string | null } | null; variant: string | null; seller: string | null };

export default function SubscriptionsScreen() {
  const { c } = useTheme();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['subscriptions'], queryFn: () => get<{ subscriptions: Sub[] }>('/subscriptions').then((r) => r.subscriptions) });
  const act = useMutation({
    mutationFn: ({ id, action }: { id: number; action: string }) => patch<{ message: string }>(`/subscriptions/${id}`, { action }),
    onSuccess: (r) => {
      toast(r.message);
      void qc.invalidateQueries({ queryKey: ['subscriptions'] });
    },
    onError: (e) => toast(e instanceof ApiError ? e.message : 'That didn\'t work.', 'error'),
  });

  return (
    <FormScreen title="Subscribe & Save" intro="Parts delivered on a schedule at a discount. Skip, pause or cancel anytime.">
      {q.data && !q.data.length ? (
        <Empty icon="refresh" title="No subscriptions" body="Look for Subscribe & Save on parts you replace regularly, like filters and wipers.">
          <Button title="Browse parts" onPress={() => router.navigate('/shop')} />
        </Empty>
      ) : null}
      {q.data?.map((s, i) => (
        <Animated.View key={s.id} entering={FadeInDown.delay(i * 60).springify()} layout={LinearTransition.springify()} style={[styles.card, { borderColor: c.line, backgroundColor: c.surface, opacity: s.status === 'cancelled' ? 0.55 : 1 }]}>
          <View style={{ flexDirection: 'row', gap: 12 }}>
            <Image source={s.product?.image} style={[styles.img, { backgroundColor: c.surface2 }]} contentFit="cover" />
            <View style={{ flex: 1 }}>
              <Text variant="smallStrong" numberOfLines={2}>
                {s.product?.title}
              </Text>
              <Text variant="small" tone="muted">
                {[s.variant, `${s.quantity} × ${s.interval.toLowerCase()}`, `${s.discount_pct}% off`].filter(Boolean).join(' · ')}
              </Text>
              <Text variant="smallStrong" style={{ marginTop: 4, textTransform: 'capitalize' }} tone={s.status === 'active' ? 'success' : 'muted'}>
                {s.status}
                {s.status === 'active' && s.next_run_at ? ` · next ${new Date(s.next_run_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}` : ''}
              </Text>
            </View>
          </View>
          {s.status !== 'cancelled' ? (
            <View style={styles.actions}>
              {s.status === 'active' ? (
                <>
                  <Button title="Skip next" size="sm" kind="outline" onPress={() => act.mutate({ id: s.id, action: 'skip' })} />
                  <Button title="Pause" size="sm" kind="outline" onPress={() => act.mutate({ id: s.id, action: 'pause' })} />
                </>
              ) : (
                <Button title="Resume" size="sm" onPress={() => act.mutate({ id: s.id, action: 'resume' })} />
              )}
              <Button
                title="Cancel"
                size="sm"
                kind="ghost"
                onPress={() => Alert.alert('Cancel this subscription?', 'No more deliveries will be sent.', [{ text: 'Keep it', style: 'cancel' }, { text: 'Cancel it', style: 'destructive', onPress: () => act.mutate({ id: s.id, action: 'cancel' }) }])}
              />
            </View>
          ) : null}
        </Animated.View>
      ))}
    </FormScreen>
  );
}

const styles = StyleSheet.create({
  card: { borderWidth: 1, borderRadius: radius.lg, padding: 14 },
  img: { width: 60, height: 60, borderRadius: 12 },
  actions: { flexDirection: 'row', gap: 8, marginTop: 12, flexWrap: 'wrap' },
});
