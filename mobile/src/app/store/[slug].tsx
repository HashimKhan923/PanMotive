import { FlashList } from '@shopify/flash-list';
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as Clipboard from 'expo-clipboard';
import { Image } from 'expo-image';
import { LinearGradient } from 'expo-linear-gradient';
import { router, useLocalSearchParams } from 'expo-router';
import { useMemo, useState } from 'react';
import { Share, StyleSheet, TextInput, View, useWindowDimensions } from 'react-native';
import Animated, { Extrapolation, FadeInDown, interpolate, useAnimatedScrollHandler, useAnimatedStyle, useSharedValue, withSequence, withSpring } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError, get, post } from '@/api/client';
import type { PageMeta, ProductCard as Card } from '@/api/types';
import { useSession } from '@/state/session';
import { Icon } from '@/ui/Icon';
import { haptic, Press } from '@/ui/Press';
import { ProductCard } from '@/ui/ProductCard';
import { GUTTER, ProductRail } from '@/ui/Section';
import { Skeleton } from '@/ui/Skeleton';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { spring } from '@/theme/motion';
import { useTheme } from '@/theme/theme';
import { font, palette, radius } from '@/theme/tokens';

type Store = {
  id: number;
  name: string;
  slug: string;
  logo: string | null;
  banner: string | null;
  rating: number;
  rating_count: number;
  tier: string | null;
  reply_time: string | null;
  away: boolean;
  headline: string | null;
  subtext: string | null;
  about: string | null;
  return_policy: string | null;
  return_window_days: number;
  since: string | null;
  vacation_message: string | null;
  announcement: string | null;
  listings: number;
  followers: number;
  following: boolean;
  offers: { code: string; type: string; value: number; min_order: number | null; expires_at: string | null }[];
  featured: Card[];
  loyalty: { points_per_dollar: number; redeem_cents_per_point: number; min_redeem: number; points: number | null } | null;
  share_url: string;
};

const SORTS: [string, string][] = [
  ['', 'Newest'],
  ['price_asc', 'Price ↑'],
  ['price_desc', 'Price ↓'],
  ['rating', 'Top rated'],
];

/** A seller's store: banner with parallax, logo, follow, store coupons, featured parts and listings. */
export default function StoreScreen() {
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const { c } = useTheme();
  const { width } = useWindowDimensions();
  const insets = useSafeAreaInsets();
  const qc = useQueryClient();
  const signedIn = !!useSession((s) => s.token);
  const [sort, setSort] = useState('');
  const [text, setText] = useState('');
  const [term, setTerm] = useState('');
  const cardW = Math.floor((width - GUTTER * 2 - 12) / 2);

  const store = useQuery({ queryKey: ['store', slug], queryFn: () => get<{ store: Store }>(`/stores/${slug}`).then((r) => r.store) });
  const list = useInfiniteQuery({
    queryKey: ['store-products', slug, sort, term],
    initialPageParam: 1,
    queryFn: ({ pageParam }) => get<{ products: Card[]; meta: PageMeta }>(`/stores/${slug}/products`, { page: pageParam, sort, q: term }),
    getNextPageParam: (last) => (last.meta.page < last.meta.last_page ? last.meta.page + 1 : undefined),
  });
  const products = useMemo(() => list.data?.pages.flatMap((p) => p.products) ?? [], [list.data]);
  const s = store.data;

  const pop = useSharedValue(1);
  const follow = useMutation({
    mutationFn: () => post<{ following: boolean; followers: number }>(`/stores/${slug}/follow`),
    onSuccess: (r) => {
      qc.setQueryData(['store', slug], (old: Store | undefined) => (old ? { ...old, following: r.following, followers: r.followers } : old));
      pop.value = withSequence(withSpring(1.15, spring.pop), withSpring(1, spring.pop));
      toast(r.following ? `Following ${s?.name}` : 'Unfollowed', r.following ? 'success' : 'info');
    },
    onError: (e) => toast(e instanceof ApiError ? e.message : 'Couldn\'t follow right now.', 'error'),
  });
  const popStyle = useAnimatedStyle(() => ({ transform: [{ scale: pop.value }] }));

  const y = useSharedValue(0);
  const onScroll = useAnimatedScrollHandler((e) => {
    y.value = e.contentOffset.y;
  });
  const banner = useAnimatedStyle(() => ({
    transform: [{ translateY: interpolate(y.value, [-150, 0, 200], [-75, 0, 100], Extrapolation.CLAMP) }, { scale: interpolate(y.value, [-150, 0], [1.4, 1], Extrapolation.CLAMP) }],
  }));

  const AnimatedList = Animated.createAnimatedComponent(FlashList<Card>);

  const header = (
    <View>
      <View style={{ height: 180, overflow: 'hidden' }}>
        <Animated.View style={[StyleSheet.absoluteFill, banner]}>
          {s?.banner ? (
            <Image source={s.banner} style={StyleSheet.absoluteFill} contentFit="cover" />
          ) : (
            <View style={StyleSheet.absoluteFill}>
              <LinearGradient colors={['#15191E', '#262b33']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 0 }} style={StyleSheet.absoluteFill} />
              <View style={styles.cutRed} />
              <View style={styles.cutLine} />
            </View>
          )}
        </Animated.View>
      </View>
      <View style={{ paddingHorizontal: GUTTER }}>
        <View style={styles.idRow}>
          {s?.logo ? (
            <Image source={s.logo} style={[styles.logo, { borderColor: c.bg, backgroundColor: c.surface }]} contentFit="contain" />
          ) : (
            <View style={[styles.logo, { borderColor: c.bg, backgroundColor: palette.charcoal, alignItems: 'center', justifyContent: 'center' }]}>
              <Text variant="h1" style={{ color: '#fff' }}>
                {s?.name.charAt(0).toUpperCase() ?? ''}
              </Text>
            </View>
          )}
          {s ? (
            <Animated.View style={popStyle}>
              <Press
                onPress={() => (signedIn ? follow.mutate() : router.push('/sign-in'))}
                style={[styles.follow, { backgroundColor: s.following ? c.surface2 : palette.red }]}
                accessibilityLabel={s.following ? 'Unfollow store' : 'Follow store'}>
                <Icon name={s.following ? 'check' : 'plus'} size={16} color={s.following ? c.fg : '#fff'} />
                <Text variant="smallStrong" style={{ color: s.following ? c.fg : '#fff' }}>
                  {s.following ? 'Following' : 'Follow'}
                </Text>
              </Press>
            </Animated.View>
          ) : null}
        </View>
        {!s ? (
          <View style={{ gap: 10, marginTop: 12 }}>
            <Skeleton width="60%" height={26} />
            <Skeleton width="80%" height={14} />
          </View>
        ) : (
          <Animated.View entering={FadeInDown.springify()}>
            <Text variant="h1" style={{ marginTop: 10 }}>
              {s.name}
            </Text>
            <Text variant="small" tone="muted" style={{ marginTop: 4 }}>
              {[s.rating > 0 ? `${s.rating.toFixed(1)}★ (${s.rating_count})` : null, `${s.listings} listings`, `${s.followers} followers`, s.reply_time]
                .filter(Boolean)
                .join(' · ')}
            </Text>
            {s.headline ? (
              <Text variant="bodyStrong" style={{ marginTop: 12 }}>
                {s.headline}
              </Text>
            ) : null}
            {s.about ? (
              <Text variant="small" tone="muted" style={{ marginTop: 6 }} numberOfLines={4}>
                {s.about}
              </Text>
            ) : null}
            {s.vacation_message || s.announcement ? (
              <View style={[styles.note, { backgroundColor: s.vacation_message ? c.warningBg : c.surface2 }]}>
                <Icon name={s.vacation_message ? 'clock' : 'bell'} size={16} color={s.vacation_message ? c.warning : c.fg} />
                <Text variant="small" style={{ flex: 1, color: s.vacation_message ? c.warning : c.fg }}>
                  {s.vacation_message ?? s.announcement}
                </Text>
              </View>
            ) : null}
            {s.offers.map((o) => (
              <Press
                key={o.code}
                onPress={async () => {
                  await Clipboard.setStringAsync(o.code);
                  haptic('success');
                  toast(`Copied ${o.code} — use it at checkout`);
                }}
                style={[styles.coupon, { borderColor: palette.red }]}>
                <Icon name="tag" size={16} />
                <Text variant="smallStrong" style={{ letterSpacing: 1 }}>
                  {o.code}
                </Text>
                <Text variant="small" tone="muted" style={{ flex: 1 }}>
                  {o.type === 'percentage' ? `${o.value}% off` : `$${o.value} off`}
                  {o.min_order ? ` orders over $${o.min_order}` : ''}
                </Text>
                <Icon name="copy" size={16} color={c.muted} />
              </Press>
            ))}
            {s.loyalty ? (
              <View style={[styles.note, { backgroundColor: c.surface2 }]}>
                <Icon name="sparkles" size={16} />
                <Text variant="small" style={{ flex: 1 }}>
                  Earn {s.loyalty.points_per_dollar} points per $1 here.{s.loyalty.points != null ? ` You have ${s.loyalty.points} points.` : ''}
                </Text>
              </View>
            ) : null}
          </Animated.View>
        )}
      </View>

      {s?.featured.length ? (
        <View style={{ marginTop: 20 }}>
          <Text variant="h3" style={{ paddingHorizontal: GUTTER, marginBottom: 10 }}>
            Featured
          </Text>
          <ProductRail products={s.featured} />
        </View>
      ) : null}

      <View style={{ paddingHorizontal: GUTTER, marginTop: 22, gap: 10 }}>
        <View style={[styles.search, { borderColor: c.line, backgroundColor: c.surface }]}>
          <Icon name="search" size={18} color={c.muted} />
          <TextInput
            value={text}
            onChangeText={setText}
            onSubmitEditing={() => setTerm(text.trim())}
            returnKeyType="search"
            placeholder="Search this store"
            placeholderTextColor={c.muted}
            style={{ flex: 1, color: c.fg, fontFamily: font.regular, fontSize: 15 }}
          />
        </View>
        <View style={{ flexDirection: 'row', gap: 8 }}>
          {SORTS.map(([k, label]) => (
            <Press key={k} onPress={() => setSort(k)} haptic="selection" style={[styles.sort, { borderColor: sort === k ? palette.red : c.line }]}>
              <Text variant="smallStrong" style={{ fontSize: 12 }}>
                {label}
              </Text>
            </Press>
          ))}
        </View>
      </View>
      <View style={{ height: 12 }} />
    </View>
  );

  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <AnimatedList
        data={products}
        numColumns={2}
        keyExtractor={(p) => String(p.id)}
        onScroll={onScroll}
        scrollEventThrottle={16}
        ListHeaderComponent={header}
        contentContainerStyle={{ paddingBottom: insets.bottom + 40 }}
        onEndReached={() => list.hasNextPage && void list.fetchNextPage()}
        renderItem={({ item, index }) => (
          <View style={{ paddingLeft: index % 2 === 0 ? GUTTER : 6, paddingRight: index % 2 === 0 ? 6 : GUTTER, paddingBottom: 12 }}>
            <ProductCard product={item} width={cardW} index={index % 24} />
          </View>
        )}
      />
      <View style={[styles.bar, { top: insets.top + 6 }]}>
        <Press onPress={() => router.back()} style={styles.round} accessibilityLabel="Back">
          <Icon name="arrow-left" size={20} color="#fff" />
        </Press>
        {s ? (
          <Press onPress={() => void Share.share({ message: `${s.name} on Panmotive — ${s.share_url}`, url: s.share_url })} style={styles.round} accessibilityLabel="Share store">
            <Icon name="external" size={18} color="#fff" />
          </Press>
        ) : null}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  cutRed: { position: 'absolute', right: -30, top: -20, bottom: -20, width: '34%', backgroundColor: palette.red, transform: [{ skewX: '-16deg' }] },
  cutLine: { position: 'absolute', right: '31%', top: -20, bottom: -20, width: 5, backgroundColor: 'rgba(229,27,43,0.7)', transform: [{ skewX: '-16deg' }] },
  idRow: { flexDirection: 'row', alignItems: 'flex-end', justifyContent: 'space-between', marginTop: -40 },
  logo: { width: 84, height: 84, borderRadius: 22, borderWidth: 4, overflow: 'hidden' },
  follow: { flexDirection: 'row', alignItems: 'center', gap: 6, borderRadius: 999, paddingHorizontal: 16, height: 40 },
  note: { flexDirection: 'row', gap: 8, alignItems: 'center', borderRadius: radius.md, padding: 12, marginTop: 12 },
  coupon: { flexDirection: 'row', alignItems: 'center', gap: 8, borderWidth: 1.5, borderStyle: 'dashed', borderRadius: radius.md, padding: 12, marginTop: 12 },
  search: { flexDirection: 'row', alignItems: 'center', gap: 8, height: 46, borderWidth: 1, borderRadius: radius.pill, paddingHorizontal: 14 },
  sort: { borderWidth: 1.5, borderRadius: radius.pill, paddingHorizontal: 12, height: 34, justifyContent: 'center' },
  bar: { position: 'absolute', left: 12, right: 12, flexDirection: 'row', justifyContent: 'space-between' },
  round: { width: 40, height: 40, borderRadius: 20, backgroundColor: 'rgba(21,25,30,0.55)', alignItems: 'center', justifyContent: 'center' },
});
