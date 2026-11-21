import { useQuery } from '@tanstack/react-query';
import { LinearGradient } from 'expo-linear-gradient';
import { router } from 'expo-router';
import { useEffect } from 'react';
import { RefreshControl, ScrollView, StyleSheet, View, useWindowDimensions } from 'react-native';
import Animated, {
  Extrapolation,
  FadeInDown,
  FadeInUp,
  interpolate,
  useAnimatedScrollHandler,
  useAnimatedStyle,
  useSharedValue,
  withRepeat,
  withSequence,
  withSpring,
  withTiming,
} from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { get } from '@/api/client';
import type { Category, Home } from '@/api/types';
import { BannerSlider } from '@/features/home/BannerSlider';
import { openLink } from '@/nav/links';
import { usePrefs } from '@/state/prefs';
import { useGarage } from '@/state/session';
import { Icon } from '@/ui/Icon';
import { Logo } from '@/ui/Logo';
import { Press } from '@/ui/Press';
import { Gap, GUTTER, ProductGrid, ProductRail, SectionTitle } from '@/ui/Section';
import { Skeleton } from '@/ui/Skeleton';
import { TAB_BAR_HEIGHT } from '@/ui/TabBar';
import { Text } from '@/ui/Text';
import { spring, STAGGER } from '@/theme/motion';
import { useTheme } from '@/theme/theme';
import { palette, radius } from '@/theme/tokens';

const HEADER_H = 56;

export default function HomeScreen() {
  const { c, dark } = useTheme();
  const insets = useSafeAreaInsets();
  const vehicle = useGarage((s) => s.vehicle);
  const recent = usePrefs((s) => s.recentlyViewed);
  const toggleScheme = usePrefs((s) => s.toggleScheme);

  const home = useQuery({ queryKey: ['home', vehicle?.id ?? null], queryFn: ({ signal }) => get<Home>('/home', undefined, signal) });
  const recentQ = useQuery({
    queryKey: ['recent', recent.slice(0, 8).join(',')],
    queryFn: () => get<{ products: Home['new'] }>('/products/by-ids', { ids: recent.slice(0, 8).join(',') }),
    enabled: recent.length > 0,
  });
  const d = home.data;

  // Smart header: slides away while scrolling down, comes back on the first scroll up.
  const y = useSharedValue(0);
  const shown = useSharedValue(1);
  const last = useSharedValue(0);
  const onScroll = useAnimatedScrollHandler((e) => {
    const dy = e.contentOffset.y - last.value;
    if (e.contentOffset.y < 80) shown.value = withTiming(1);
    else if (dy > 6) shown.value = withTiming(0, { duration: 220 });
    else if (dy < -6) shown.value = withTiming(1, { duration: 220 });
    last.value = e.contentOffset.y;
    y.value = e.contentOffset.y;
  });
  const header = useAnimatedStyle(() => ({
    transform: [{ translateY: (shown.value - 1) * (HEADER_H + insets.top) }],
    backgroundColor: `rgba(${dark ? '21,25,30' : '255,255,255'},${interpolate(y.value, [0, 60], [0, 0.94], Extrapolation.CLAMP)})`,
    borderBottomColor: `rgba(${dark ? '62,70,82' : '217,221,227'},${interpolate(y.value, [0, 60], [0, 1], Extrapolation.CLAMP)})`,
  }));
  // The hero drifts up slower than the page and fades as it leaves (parallax).
  const hero = useAnimatedStyle(() => ({
    transform: [{ translateY: interpolate(y.value, [-100, 0, 400], [-40, 0, 140], Extrapolation.CLAMP) }],
    opacity: interpolate(y.value, [0, 320], [1, 0.2], Extrapolation.CLAMP),
  }));

  const banners = d?.banners ?? {};

  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <Animated.ScrollView
        onScroll={onScroll}
        scrollEventThrottle={16}
        contentContainerStyle={{ paddingTop: insets.top + HEADER_H, paddingBottom: TAB_BAR_HEIGHT + insets.bottom + 40 }}
        refreshControl={<RefreshControl refreshing={home.isRefetching} onRefresh={() => void home.refetch()} tintColor={palette.red} colors={[palette.red]} progressViewOffset={insets.top + HEADER_H} />}>
        {/* Hero */}
        <Animated.View style={[styles.hero, hero]}>
          <LinearGradient
            colors={dark ? ['rgba(229,27,43,0.16)', 'rgba(21,25,30,0)'] : ['rgba(229,27,43,0.08)', 'rgba(255,255,255,0)']}
            start={{ x: 1, y: 0 }}
            end={{ x: 0.2, y: 0.9 }}
            style={StyleSheet.absoluteFill}
          />
          {d ? (
            <>
              <Animated.View entering={FadeInDown.delay(80).springify()} style={[styles.badge, { borderColor: c.line, backgroundColor: c.surface }]}>
                <Icon name="sparkles" size={16} />
                <Text variant="smallStrong" tone="muted">
                  {d.hero.badge}
                </Text>
              </Animated.View>
              <Animated.View entering={FadeInDown.delay(160).springify()}>
                <Text variant="hero" style={{ marginTop: 16 }}>
                  {d.hero.line_1}
                  {d.hero.line_2 ? `\n${d.hero.line_2}` : ''}
                </Text>
                <Text variant="hero" style={{ color: c.muted }}>
                  {d.hero.accent}
                </Text>
              </Animated.View>
              <Animated.View entering={FadeInDown.delay(240).springify()}>
                <Text variant="body" tone="muted" style={{ marginTop: 12 }}>
                  {d.hero.text}
                </Text>
              </Animated.View>
            </>
          ) : (
            <View style={{ gap: 12 }}>
              <Skeleton width={180} height={28} radius={14} />
              <Skeleton width="85%" height={40} />
              <Skeleton width="60%" height={40} />
            </View>
          )}
          <Animated.View entering={FadeInUp.delay(320).springify()}>
            <GarageChip label={vehicle?.label ?? null} />
          </Animated.View>
          {d?.hero.points.length ? (
            <View style={styles.points}>
              {d.hero.points.map((p) => (
                <View key={p} style={styles.point}>
                  <Icon name="check" size={16} />
                  <Text variant="small" tone="muted">
                    {p}
                  </Text>
                </View>
              ))}
            </View>
          ) : null}
        </Animated.View>

        {d?.announcement ? (
          <Press onPress={() => openLink(d.announcement!.link)} style={[styles.announce, { backgroundColor: palette.red }]} haptic="selection">
            <Text variant="smallStrong" style={{ color: '#fff', flex: 1 }}>
              {d.announcement.text}
            </Text>
            {d.announcement.link ? <Icon name="arrow-right" size={16} color="#fff" /> : null}
          </Press>
        ) : null}

        <Gap size={28} />
        <SectionTitle title="Shop by category" subtitle="Find parts and accessories by category." action="All" onAction={() => router.push('/categories')} />
        <CategoryGrid categories={d?.categories} />

        {banners.below_categories?.length ? (
          <>
            <Gap />
            <BannerSlider banners={banners.below_categories} />
          </>
        ) : null}

        {vehicle && (d?.for_vehicle.length || home.isLoading) ? (
          <>
            <Gap />
            <SectionTitle title="For your vehicle" subtitle={vehicle.label} action="See all" onAction={() => router.push({ pathname: '/shop', params: { vehicleId: String(vehicle.id) } })} />
            <ProductRail products={d?.for_vehicle} loading={home.isLoading} />
          </>
        ) : null}

        <Gap />
        <SectionTitle title="Today's deals" action="See all deals" onAction={() => router.navigate('/deals')} />
        <ProductRail products={d?.deals} loading={home.isLoading} />

        {banners.below_deals?.length ? (
          <>
            <Gap />
            <BannerSlider banners={banners.below_deals} />
          </>
        ) : null}

        {d?.from_followed.length ? (
          <>
            <Gap />
            <SectionTitle title="From stores you follow" />
            <ProductRail products={d.from_followed} />
          </>
        ) : null}

        <Gap />
        <SectionTitle title="New listings" action="Browse all parts" onAction={() => router.push('/shop')} />
        <ProductGrid products={d?.new} loading={home.isLoading} />

        {recentQ.data?.products.length ? (
          <>
            <Gap />
            <SectionTitle title="Recently viewed" />
            <ProductRail products={recentQ.data.products} />
          </>
        ) : null}

        {banners.below_listings?.length ? (
          <>
            <Gap />
            <BannerSlider banners={banners.below_listings} />
          </>
        ) : null}

        <Gap />
        <TrustStrip />
      </Animated.ScrollView>

      {/* Header */}
      <Animated.View style={[styles.header, { paddingTop: insets.top, height: insets.top + HEADER_H }, header]}>
        <Logo height={22} />
        <View style={{ flexDirection: 'row', gap: 6 }}>
          <Press onPress={toggleScheme} style={styles.headerBtn} accessibilityLabel={dark ? 'Switch to light mode' : 'Switch to dark mode'}>
            <Icon name={dark ? 'sun' : 'moon'} size={22} />
          </Press>
          <Press onPress={() => router.push('/wishlist')} style={styles.headerBtn} accessibilityLabel="Saved parts">
            <Icon name="heart" size={22} />
          </Press>
        </View>
      </Animated.View>
    </View>
  );
}

/** "Shopping for: 2019 Honda Civic" — or, with no vehicle yet, an invitation with a soft red pulse. */
function GarageChip({ label }: { label: string | null }) {
  const { c } = useTheme();
  const pulse = useSharedValue(0);

  useEffect(() => {
    if (!label) pulse.value = withRepeat(withSequence(withTiming(1, { duration: 1400 }), withTiming(0, { duration: 0 })), -1);
    else pulse.value = 0;
  }, [label, pulse]);
  const ring = useAnimatedStyle(() => ({ opacity: label ? 0 : 0.55 * (1 - pulse.value), transform: [{ scale: 1 + pulse.value * 0.08 }] }));

  return (
    <Press onPress={() => router.push('/garage')} scaleTo={0.98} style={{ marginTop: 22 }} accessibilityLabel={label ? `Shopping for ${label}. Change vehicle` : 'Add your vehicle'}>
      <Animated.View style={[StyleSheet.absoluteFill, styles.chipRing, ring]} />
      <View style={[styles.chip, { backgroundColor: label ? c.surface : palette.red, borderColor: label ? c.line : palette.red }]}>
        <View style={[styles.chipIcon, { backgroundColor: label ? c.surface2 : 'rgba(255,255,255,0.18)' }]}>
          <Icon name="vehicle" size={22} color={label ? c.fg : '#fff'} />
        </View>
        <View style={{ flex: 1 }}>
          <Text variant="tiny" style={{ color: label ? c.muted : 'rgba(255,255,255,0.85)', fontSize: 10 }}>
            {label ? 'Shopping for' : 'Find parts that fit'}
          </Text>
          <Text variant="bodyStrong" numberOfLines={1} style={{ color: label ? c.fg : '#fff' }}>
            {label ?? 'Add your vehicle'}
          </Text>
        </View>
        <Icon name={label ? 'chevron-right' : 'plus'} size={20} color={label ? c.muted : '#fff'} />
      </View>
    </Press>
  );
}

function CategoryGrid({ categories }: { categories?: Category[] }) {
  const { c } = useTheme();
  const { width } = useWindowDimensions();
  const w = Math.floor((width - GUTTER * 2 - 10) / 2);

  if (!categories) {
    return (
      <View style={styles.cats}>
        {Array.from({ length: 6 }).map((_, i) => (
          <Skeleton key={i} width={w} height={64} radius={radius.lg} />
        ))}
      </View>
    );
  }

  return (
    <View style={styles.cats}>
      {categories.map((cat, i) => (
        <Animated.View key={cat.id} entering={FadeInDown.delay(i * STAGGER).springify().damping(spring.gentle.damping)}>
          <Press onPress={() => router.push({ pathname: '/shop', params: { category: cat.slug } })} scaleTo={0.96} style={[styles.cat, { width: w, backgroundColor: c.surface, borderColor: c.line }]}>
            <View style={[styles.catIcon, { backgroundColor: c.surface2 }]}>
              <Icon name={cat.icon} size={22} />
            </View>
            <View style={{ flex: 1 }}>
              <Text variant="smallStrong" numberOfLines={2}>
                {cat.name}
              </Text>
              {cat.count != null ? (
                <Text variant="small" tone="muted" style={{ fontSize: 11 }}>
                  {cat.count.toLocaleString()} {cat.count === 1 ? 'part' : 'parts'}
                </Text>
              ) : null}
            </View>
          </Press>
        </Animated.View>
      ))}
    </View>
  );
}

function TrustStrip() {
  const { c } = useTheme();
  const items: [string, string, string][] = [
    ['shield', 'Payment held until delivery', 'Sellers are paid after your order is delivered.'],
    ['sparkles', 'Cashback on delivered orders', 'Cashback goes to your wallet. Use it at checkout.'],
    ['delivery', 'Listed vehicle compatibility', 'Sellers list the vehicles a part fits. Check the list before ordering.'],
  ];

  return (
    <View style={[styles.trust, { backgroundColor: c.surface, borderColor: c.line }]}>
      {items.map(([icon, title, body]) => (
        <View key={title} style={{ flexDirection: 'row', gap: 12 }}>
          <View style={[styles.catIcon, { backgroundColor: c.surface2 }]}>
            <Icon name={icon} size={20} />
          </View>
          <View style={{ flex: 1 }}>
            <Text variant="smallStrong">{title}</Text>
            <Text variant="small" tone="muted">
              {body}
            </Text>
          </View>
        </View>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  header: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    paddingHorizontal: GUTTER,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  headerBtn: { width: 40, height: 40, alignItems: 'center', justifyContent: 'center' },
  hero: { paddingHorizontal: GUTTER, paddingTop: 20, paddingBottom: 8, overflow: 'hidden' },
  badge: { flexDirection: 'row', alignItems: 'center', gap: 6, alignSelf: 'flex-start', borderWidth: 1, borderRadius: 999, paddingHorizontal: 12, paddingVertical: 6 },
  points: { marginTop: 16, gap: 6 },
  point: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  chip: { flexDirection: 'row', alignItems: 'center', gap: 12, borderRadius: radius.lg, borderWidth: 1, padding: 10 },
  chipRing: { borderRadius: radius.lg + 4, borderWidth: 2, borderColor: palette.red, margin: -4 },
  chipIcon: { width: 44, height: 44, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  announce: { marginHorizontal: GUTTER, marginTop: 20, borderRadius: radius.md, padding: 14, flexDirection: 'row', alignItems: 'center', gap: 8 },
  cats: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, paddingHorizontal: GUTTER },
  cat: { flexDirection: 'row', alignItems: 'center', gap: 10, borderWidth: 1, borderRadius: radius.lg, padding: 10, minHeight: 64 },
  catIcon: { width: 40, height: 40, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  trust: { marginHorizontal: GUTTER, borderRadius: radius.lg, borderWidth: 1, padding: 16, gap: 16 },
});
