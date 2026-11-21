import { FlashList } from '@shopify/flash-list';
import { useInfiniteQuery } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router } from 'expo-router';
import { useMemo } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated, { FadeInDown } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { get } from '@/api/client';
import type { PageMeta } from '@/api/types';
import { Button } from '@/ui/Button';
import { Icon } from '@/ui/Icon';
import { Press } from '@/ui/Press';
import { money } from '@/ui/Price';
import { Empty, GUTTER } from '@/ui/Section';
import { Skeleton } from '@/ui/Skeleton';
import { Text } from '@/ui/Text';
import { STAGGER } from '@/theme/motion';
import { useTheme } from '@/theme/theme';
import { palette, radius } from '@/theme/tokens';

export type OrderRow = { number: string; status: string; status_label: string; total: number; placed_at: string; items_count: number; images: string[]; progress: number };

const STEPS = ['Placed', 'Paid', 'Shipped', 'Delivered'];

export default function OrdersScreen() {
  const { c } = useTheme();
  const insets = useSafeAreaInsets();
  const q = useInfiniteQuery({
    queryKey: ['orders'],
    initialPageParam: 1,
    queryFn: ({ pageParam }) => get<{ orders: OrderRow[]; meta: PageMeta }>('/orders', { page: pageParam }),
    getNextPageParam: (last) => (last.meta.page < last.meta.last_page ? last.meta.page + 1 : undefined),
  });
  const orders = useMemo(() => q.data?.pages.flatMap((p) => p.orders) ?? [], [q.data]);

  return (
    <View style={{ flex: 1, backgroundColor: c.bg, paddingTop: insets.top }}>
      <View style={styles.top}>
        <Press onPress={() => router.back()} style={styles.iconBtn} accessibilityLabel="Back">
          <Icon name="arrow-left" size={22} />
        </Press>
        <Text variant="h3">Orders</Text>
        <View style={styles.iconBtn} />
      </View>
      {q.isLoading ? (
        <View style={{ padding: GUTTER, gap: 12 }}>
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} height={140} radius={radius.lg} />
          ))}
        </View>
      ) : (
        <FlashList
          data={orders}
          keyExtractor={(o) => o.number}
          contentContainerStyle={{ padding: GUTTER, paddingBottom: insets.bottom + 40 }}
          onEndReached={() => q.hasNextPage && void q.fetchNextPage()}
          onRefresh={() => void q.refetch()}
          refreshing={q.isRefetching}
          ListEmptyComponent={
            <Empty icon="package" title="No orders yet" body="When you buy a part, you can follow it here from payment to your door.">
              <Button title="Start shopping" onPress={() => router.navigate('/shop')} />
            </Empty>
          }
          renderItem={({ item: o, index }) => (
            <Animated.View entering={FadeInDown.delay(Math.min(index, 8) * STAGGER).springify()} style={{ marginBottom: 12 }}>
              <Press onPress={() => router.push({ pathname: '/order/[number]', params: { number: o.number } })} scaleTo={0.98} style={[styles.card, { backgroundColor: c.surface, borderColor: c.line }]}>
                <View style={styles.head}>
                  <View>
                    <Text variant="bodyStrong">{o.number}</Text>
                    <Text variant="small" tone="muted">
                      {new Date(o.placed_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })} · {o.items_count} {o.items_count === 1 ? 'item' : 'items'}
                    </Text>
                  </View>
                  <Text variant="bodyStrong">{money(o.total)}</Text>
                </View>
                <View style={{ flexDirection: 'row', gap: 6, marginTop: 12 }}>
                  {o.images.map((u, i) => (
                    <Image key={i} source={u} style={[styles.thumb, { backgroundColor: c.surface2 }]} contentFit="cover" />
                  ))}
                </View>
                <View style={styles.steps}>
                  {STEPS.map((s, i) => {
                    const done = o.progress > i;
                    return (
                      <View key={s} style={{ flex: 1 }}>
                        <View style={[styles.seg, { backgroundColor: done ? palette.red : c.surface2 }]} />
                        <Text variant="small" style={{ fontSize: 10.5, marginTop: 4, color: done ? c.fg : c.muted }}>
                          {s}
                        </Text>
                      </View>
                    );
                  })}
                </View>
                <View style={[styles.status, { backgroundColor: o.status === 'placed' ? 'rgba(229,27,43,0.12)' : c.surface2 }]}>
                  <Text variant="smallStrong" style={{ fontSize: 12 }}>
                    {o.status_label}
                  </Text>
                </View>
              </Press>
            </Animated.View>
          )}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 8, height: 52 },
  iconBtn: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  card: { borderWidth: 1, borderRadius: radius.lg, padding: 14 },
  head: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start' },
  thumb: { width: 48, height: 48, borderRadius: 10 },
  steps: { flexDirection: 'row', gap: 4, marginTop: 14 },
  seg: { height: 4, borderRadius: 2, transform: [{ skewX: '-16deg' }] },
  status: { position: 'absolute', right: 14, bottom: 46, borderRadius: 999, paddingHorizontal: 10, paddingVertical: 3 },
});
