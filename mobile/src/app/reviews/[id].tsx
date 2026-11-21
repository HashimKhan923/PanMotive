import { useInfiniteQuery, useMutation } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useMemo, useState } from 'react';
import { FlatList, StyleSheet, View } from 'react-native';
import Animated, { FadeInDown } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { post, get } from '@/api/client';
import type { PageMeta } from '@/api/types';
import type { Review } from '@/features/product/Social';
import { Icon } from '@/ui/Icon';
import { Press } from '@/ui/Press';
import { Rating } from '@/ui/Rating';
import { Text } from '@/ui/Text';
import { useTheme } from '@/theme/theme';
import { palette, radius } from '@/theme/tokens';

/** Every review for a part, newest or most helpful first; mark the useful ones. */
export default function ReviewsScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { c } = useTheme();
  const insets = useSafeAreaInsets();
  const [sort, setSort] = useState<'' | 'helpful'>('');
  const [helped, setHelped] = useState<Record<number, number>>({});
  const q = useInfiniteQuery({
    queryKey: ['reviews-all', id, sort],
    initialPageParam: 1,
    queryFn: ({ pageParam }) => get<{ reviews: Review[]; meta: PageMeta }>(`/products/${id}/reviews`, { page: pageParam, sort }),
    getNextPageParam: (l) => (l.meta.page < l.meta.last_page ? l.meta.page + 1 : undefined),
  });
  const rows = useMemo(() => q.data?.pages.flatMap((p) => p.reviews) ?? [], [q.data]);
  const helpful = useMutation({
    mutationFn: (rid: number) => post<{ helpful: number }>(`/reviews/${rid}/helpful`),
    onSuccess: (r, rid) => setHelped((h) => ({ ...h, [rid]: r.helpful })),
  });

  return (
    <View style={{ flex: 1, backgroundColor: c.bg, paddingTop: insets.top }}>
      <View style={styles.top}>
        <Press onPress={() => router.back()} style={styles.iconBtn} accessibilityLabel="Back">
          <Icon name="arrow-left" size={22} />
        </Press>
        <Text variant="h3">Reviews</Text>
        <View style={styles.iconBtn} />
      </View>
      <View style={{ flexDirection: 'row', gap: 8, paddingHorizontal: 16, paddingBottom: 8 }}>
        {(['', 'helpful'] as const).map((s) => (
          <Press key={s} onPress={() => setSort(s)} haptic="selection" style={[styles.chip, { borderColor: sort === s ? palette.red : c.line }]}>
            <Text variant="smallStrong">{s ? 'Most helpful' : 'Newest'}</Text>
          </Press>
        ))}
      </View>
      <FlatList
        data={rows}
        keyExtractor={(r) => String(r.id)}
        onEndReached={() => q.hasNextPage && void q.fetchNextPage()}
        contentContainerStyle={{ padding: 16, paddingBottom: insets.bottom + 40 }}
        renderItem={({ item: r, index }) => (
          <Animated.View entering={FadeInDown.delay((index % 10) * 40)} style={[styles.item, { borderColor: c.line }]}>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
              <Rating value={r.rating} />
              {r.verified ? (
                <Text variant="small" tone="success" style={{ fontSize: 11 }}>
                  Verified purchase
                </Text>
              ) : null}
            </View>
            {r.title ? (
              <Text variant="bodyStrong" style={{ marginTop: 6 }}>
                {r.title}
              </Text>
            ) : null}
            <Text variant="body" style={{ marginTop: 4 }}>
              {r.body}
            </Text>
            <Text variant="small" tone="muted" style={{ marginTop: 6 }}>
              {r.author} · {new Date(r.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
            </Text>
            {r.seller_response ? (
              <View style={{ marginTop: 8, backgroundColor: c.surface2, borderRadius: radius.md, padding: 10 }}>
                <Text variant="smallStrong">Seller's reply</Text>
                <Text variant="small">{r.seller_response}</Text>
              </View>
            ) : null}
            <Press onPress={() => helpful.mutate(r.id)} haptic="light" style={[styles.helpful, { borderColor: c.line }]}>
              <Icon name="check" size={14} />
              <Text variant="small">Helpful ({helped[r.id] ?? r.helpful})</Text>
            </Press>
          </Animated.View>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 8, height: 52 },
  iconBtn: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  chip: { borderWidth: 1.5, borderRadius: 999, paddingHorizontal: 12, height: 34, justifyContent: 'center' },
  item: { paddingVertical: 14, borderBottomWidth: StyleSheet.hairlineWidth },
  helpful: { flexDirection: 'row', alignItems: 'center', gap: 6, alignSelf: 'flex-start', borderWidth: 1, borderRadius: 999, paddingHorizontal: 10, paddingVertical: 5, marginTop: 10 },
});
