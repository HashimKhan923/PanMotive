import { FlashList } from '@shopify/flash-list';
import { useInfiniteQuery } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { LinearGradient } from 'expo-linear-gradient';
import { router } from 'expo-router';
import { useEffect, useMemo } from 'react';
import { StyleSheet, View, useWindowDimensions } from 'react-native';
import Animated, { Easing, FadeInDown, useAnimatedStyle, useSharedValue, withRepeat, withTiming } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useAddToCart } from '@/api/actions';
import { get } from '@/api/client';
import type { PageMeta, ProductCard as Card } from '@/api/types';
import { Button } from '@/ui/Button';
import { Countdown } from '@/ui/Countdown';
import { Icon } from '@/ui/Icon';
import { Press } from '@/ui/Press';
import { money } from '@/ui/Price';
import { ProductCard } from '@/ui/ProductCard';
import { Empty, GUTTER } from '@/ui/Section';
import { CardSkeleton, Skeleton } from '@/ui/Skeleton';
import { TAB_BAR_HEIGHT } from '@/ui/TabBar';
import { Text } from '@/ui/Text';
import { useTheme } from '@/theme/theme';
import { palette, radius } from '@/theme/tokens';

type Deals = { products: Card[]; meta: PageMeta; deal_of_the_day?: Card | null; total?: number };

export default function DealsScreen() {
  const { c } = useTheme();
  const { width } = useWindowDimensions();
  const insets = useSafeAreaInsets();
  const cardW = Math.floor((width - GUTTER * 2 - 12) / 2);

  const q = useInfiniteQuery({
    queryKey: ['deals'],
    initialPageParam: 1,
    queryFn: ({ pageParam }) => get<Deals>('/deals', { page: pageParam }),
    getNextPageParam: (last) => (last.meta.page < last.meta.last_page ? last.meta.page + 1 : undefined),
  });
  const first = q.data?.pages[0];
  const products = useMemo(() => q.data?.pages.flatMap((p) => p.products) ?? [], [q.data]);

  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <FlashList
        data={products}
        numColumns={2}
        keyExtractor={(p) => String(p.id)}
        contentContainerStyle={{ paddingHorizontal: GUTTER - 6, paddingBottom: TAB_BAR_HEIGHT + insets.bottom + 40 }}
        onEndReached={() => q.hasNextPage && !q.isFetchingNextPage && void q.fetchNextPage()}
        onEndReachedThreshold={0.6}
        onRefresh={() => void q.refetch()}
        refreshing={q.isRefetching && !q.isFetchingNextPage}
        ListHeaderComponent={
          <View style={{ paddingTop: insets.top + 12, paddingHorizontal: 6 }}>
            <View style={styles.titleRow}>
              <View style={styles.live}>
                <LiveDot />
                <Text variant="smallStrong" style={{ color: '#fff', fontSize: 11 }}>
                  LIVE DEALS
                </Text>
              </View>
            </View>
            <Text variant="h1" style={{ marginTop: 10 }}>
              Today's deals
            </Text>
            <Text variant="small" tone="muted" style={{ marginTop: 2 }}>
              {first?.total != null ? `Prices drop while stock lasts. ${first.total} parts on sale right now.` : 'Prices drop while stock lasts.'}
            </Text>
            {q.isLoading ? <Skeleton height={220} radius={radius.xl} style={{ marginTop: 16 }} /> : first?.deal_of_the_day ? <DealOfTheDay p={first.deal_of_the_day} /> : null}
            <Text variant="h3" style={{ marginTop: 24, marginBottom: 12 }}>
              All deals
            </Text>
          </View>
        }
        ListEmptyComponent={
          q.isLoading ? (
            <View style={{ flexDirection: 'row', gap: 12, paddingHorizontal: 6 }}>
              <CardSkeleton width={cardW} />
              <CardSkeleton width={cardW} />
            </View>
          ) : (
            <Empty icon="tag" title="No deals right now" body="Check back soon — sellers add new deals every day." />
          )
        }
        renderItem={({ item, index }) => (
          <View style={{ paddingHorizontal: 6, paddingBottom: 12 }}>
            <ProductCard product={item} width={cardW} index={index % 24} />
          </View>
        )}
      />
    </View>
  );
}

function LiveDot() {
  const s = useSharedValue(1);
  useEffect(() => {
    s.value = withRepeat(withTiming(0.3, { duration: 800, easing: Easing.inOut(Easing.quad) }), -1, true);
  }, [s]);
  const style = useAnimatedStyle(() => ({ opacity: s.value }));
  return <Animated.View style={[{ width: 7, height: 7, borderRadius: 4, backgroundColor: '#fff' }, style]} />;
}

/** The biggest discount of the day, on a red card with a live countdown. */
function DealOfTheDay({ p }: { p: Card }) {
  const add = useAddToCart();

  return (
    <Animated.View entering={FadeInDown.springify().damping(16)}>
      <Press onPress={() => router.push({ pathname: '/product/[slug]', params: { slug: p.slug } })} scaleTo={0.98} style={styles.dotd}>
        <LinearGradient colors={['#BB1220', '#E51B2B']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={StyleSheet.absoluteFill} />
        <View style={styles.cut} />
        <View style={{ flex: 1, padding: 18, justifyContent: 'space-between' }}>
          <View>
            <Text variant="tiny" style={{ color: 'rgba(255,255,255,0.85)' }}>
              Deal of the day
            </Text>
            <Text variant="h3" numberOfLines={3} style={{ color: '#fff', marginTop: 6 }}>
              {p.title}
            </Text>
          </View>
          <View>
            <View style={{ flexDirection: 'row', alignItems: 'baseline', gap: 8, flexWrap: 'wrap' }}>
              <Text variant="priceLarge" style={{ color: '#fff' }}>
                {money(p.effective_price)}
              </Text>
              <Text variant="small" style={{ color: 'rgba(255,255,255,0.75)', textDecorationLine: 'line-through' }}>
                {money(p.price)}
              </Text>
              <View style={styles.offPill}>
                <Text variant="smallStrong" style={{ color: palette.red }}>
                  −{p.off_pct}%
                </Text>
              </View>
            </View>
            {p.deal_ends_at ? (
              <View style={{ marginTop: 8 }}>
                <Countdown endsAt={p.deal_ends_at} />
              </View>
            ) : null}
            {p.quick_add ? (
              <Button title="Grab this deal" kind="light" size="sm" icon="cart" style={{ marginTop: 12, alignSelf: 'flex-start' }} onPress={() => add.mutate({ productId: p.id, image: p.image })} />
            ) : null}
          </View>
        </View>
        <View style={styles.photo}>
          {p.image ? <Image source={p.image_large ?? p.image} style={StyleSheet.absoluteFill} contentFit="cover" /> : <Icon name="image" size={32} color="#fff" />}
        </View>
      </Press>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  titleRow: { flexDirection: 'row' },
  live: { flexDirection: 'row', alignItems: 'center', gap: 6, backgroundColor: palette.red, borderRadius: 999, paddingHorizontal: 10, paddingVertical: 5 },
  dotd: { marginTop: 16, height: 240, borderRadius: radius.xl, overflow: 'hidden', flexDirection: 'row' },
  cut: { position: 'absolute', right: '28%', top: -40, bottom: -40, width: 6, backgroundColor: 'rgba(255,255,255,0.35)', transform: [{ skewX: '-16deg' }] },
  photo: { width: '40%', backgroundColor: 'rgba(0,0,0,0.15)', alignItems: 'center', justifyContent: 'center', overflow: 'hidden' },
  offPill: { backgroundColor: '#fff', borderRadius: 999, paddingHorizontal: 8, paddingVertical: 2 },
});
