import { useQuery } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { Keyboard, ScrollView, StyleSheet, TextInput, View } from 'react-native';
import Animated, { FadeIn, FadeInDown, FadeOut, LinearTransition } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { get } from '@/api/client';
import type { Category, ProductCard } from '@/api/types';
import { usePrefs } from '@/state/prefs';
import { useGarage } from '@/state/session';
import { Icon } from '@/ui/Icon';
import { Press } from '@/ui/Press';
import { money } from '@/ui/Price';
import { GUTTER } from '@/ui/Section';
import { TAB_BAR_HEIGHT } from '@/ui/TabBar';
import { Text } from '@/ui/Text';
import { useTheme } from '@/theme/theme';
import { font, palette, radius } from '@/theme/tokens';

type Suggest = { products: ProductCard[]; categories: { name: string; slug: string }[]; vehicle: string | null };

function useDebounced<T>(value: T, ms = 220) {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms);
    return () => clearTimeout(t);
  }, [value, ms]);
  return v;
}

export default function SearchScreen() {
  const { c } = useTheme();
  const insets = useSafeAreaInsets();
  const [text, setText] = useState('');
  const term = useDebounced(text.trim());
  const { recentSearches, addSearch, clearSearches } = usePrefs();
  const vehicle = useGarage((s) => s.vehicle);

  const suggest = useQuery({
    queryKey: ['suggest', term, vehicle?.id ?? null],
    queryFn: ({ signal }) => get<Suggest>('/search/suggest', { q: term }, signal),
    enabled: term.length >= 2,
    placeholderData: (prev) => prev,
  });
  const cats = useQuery({ queryKey: ['categories'], queryFn: () => get<{ categories: Category[] }>('/categories').then((r) => r.categories) });

  const go = (q: string) => {
    const t = q.trim();
    if (!t) return;
    addSearch(t);
    Keyboard.dismiss();
    router.push({ pathname: '/shop', params: { q: t } });
  };

  const typing = term.length >= 2;

  return (
    <View style={{ flex: 1, backgroundColor: c.bg, paddingTop: insets.top + 8 }}>
      <View style={{ paddingHorizontal: GUTTER }}>
        <Text variant="h1">Search</Text>
        <View style={[styles.search, { backgroundColor: c.surface, borderColor: text ? palette.red : c.line }]}>
          <Icon name="search" size={20} color={c.muted} />
          <TextInput
            value={text}
            onChangeText={setText}
            onSubmitEditing={() => go(text)}
            returnKeyType="search"
            placeholder="Parts, vehicles or part numbers"
            placeholderTextColor={c.muted}
            autoCorrect={false}
            style={{ flex: 1, color: c.fg, fontFamily: font.regular, fontSize: 16 }}
          />
          {text ? (
            <Press onPress={() => setText('')} style={{ padding: 4 }} accessibilityLabel="Clear search">
              <Icon name="x" size={18} color={c.muted} />
            </Press>
          ) : null}
        </View>
      </View>

      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: GUTTER, paddingBottom: TAB_BAR_HEIGHT + insets.bottom + 40 }}>
        {typing ? (
          <Animated.View entering={FadeIn.duration(150)} exiting={FadeOut.duration(100)}>
            {suggest.data?.vehicle ? (
              <Text variant="small" tone="muted" style={{ marginBottom: 8 }}>
                Checking fit for your {suggest.data.vehicle}
              </Text>
            ) : null}
            {suggest.data?.products.map((p, i) => (
              <Animated.View key={p.id} entering={FadeInDown.delay(i * 30).duration(200)} layout={LinearTransition}>
                <Press onPress={() => router.push({ pathname: '/product/[slug]', params: { slug: p.slug } })} scaleTo={0.98} style={[styles.result, { borderColor: c.line }]}>
                  <Image source={p.image} style={[styles.thumb, { backgroundColor: c.surface2 }]} contentFit="cover" />
                  <View style={{ flex: 1 }}>
                    <Text variant="smallStrong" numberOfLines={2}>
                      {p.title}
                    </Text>
                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 3 }}>
                      <Text variant="smallStrong">{money(p.effective_price)}</Text>
                      {p.fits_garage ? (
                        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 2 }}>
                          <Icon name="check" size={14} color={c.success} />
                          <Text variant="small" tone="success" style={{ fontSize: 11 }}>
                            Fits
                          </Text>
                        </View>
                      ) : null}
                      {!p.in_stock ? (
                        <Text variant="small" tone="error" style={{ fontSize: 11 }}>
                          Out of stock
                        </Text>
                      ) : null}
                    </View>
                  </View>
                </Press>
              </Animated.View>
            ))}
            {suggest.data?.categories.map((cat) => (
              <Press key={cat.slug} onPress={() => router.push({ pathname: '/shop', params: { category: cat.slug } })} style={[styles.result, { borderColor: c.line }]}>
                <View style={[styles.thumb, { backgroundColor: c.surface2, alignItems: 'center', justifyContent: 'center' }]}>
                  <Icon name="grid" size={20} />
                </View>
                <Text variant="smallStrong" style={{ flex: 1 }}>
                  {cat.name}
                </Text>
                <Text variant="small" tone="muted">
                  Category
                </Text>
              </Press>
            ))}
            <Press onPress={() => go(text)} style={[styles.all, { backgroundColor: c.surface2 }]}>
              <Icon name="search" size={18} />
              <Text variant="bodyStrong" style={{ flex: 1 }}>
                See all results for "{text.trim()}"
              </Text>
              <Icon name="arrow-right" size={18} />
            </Press>
          </Animated.View>
        ) : (
          <Animated.View entering={FadeIn.duration(150)}>
            <View style={{ flexDirection: 'row', gap: 10 }}>
              <QuickCard icon="vehicle" title={vehicle ? 'Shop my vehicle' : 'Add vehicle'} body={vehicle?.label ?? 'Parts that fit'} onPress={() => (vehicle ? router.push({ pathname: '/shop', params: { vehicleId: String(vehicle.id) } }) : router.push('/garage'))} />
              <QuickCard icon="search" title="Scan VIN" body="Camera or type it" onPress={() => router.push('/garage')} />
            </View>

            {recentSearches.length ? (
              <>
                <View style={styles.rowHead}>
                  <Text variant="h3">Recent</Text>
                  <Press onPress={clearSearches} accessibilityLabel="Clear recent searches">
                    <Text variant="smallStrong" tone="muted">
                      Clear
                    </Text>
                  </Press>
                </View>
                <View style={styles.wrap}>
                  {recentSearches.map((s) => (
                    <Press key={s} onPress={() => go(s)} style={[styles.recent, { borderColor: c.line }]}>
                      <Icon name="clock" size={14} color={c.muted} />
                      <Text variant="smallStrong">{s}</Text>
                    </Press>
                  ))}
                </View>
              </>
            ) : null}

            <View style={styles.rowHead}>
              <Text variant="h3">Shop by category</Text>
            </View>
            {cats.data?.map((cat, i) => (
              <Animated.View key={cat.id} entering={FadeInDown.delay(i * 40).springify()}>
                <Press onPress={() => router.push({ pathname: '/shop', params: { category: cat.slug } })} scaleTo={0.98} style={[styles.cat, { borderColor: c.line }]}>
                  <View style={[styles.catIcon, { backgroundColor: c.surface2 }]}>
                    <Icon name={cat.icon} size={20} />
                  </View>
                  <Text variant="bodyStrong" style={{ flex: 1 }}>
                    {cat.name}
                  </Text>
                  {cat.count != null ? (
                    <Text variant="small" tone="muted">
                      {cat.count}
                    </Text>
                  ) : null}
                  <Icon name="chevron-right" size={18} color={c.muted} />
                </Press>
              </Animated.View>
            ))}
          </Animated.View>
        )}
      </ScrollView>
    </View>
  );
}

function QuickCard({ icon, title, body, onPress }: { icon: string; title: string; body: string; onPress: () => void }) {
  const { c } = useTheme();
  return (
    <Press onPress={onPress} scaleTo={0.96} style={[styles.quick, { backgroundColor: c.surface, borderColor: c.line }]}>
      <View style={[styles.catIcon, { backgroundColor: palette.red }]}>
        <Icon name={icon} size={20} color="#fff" />
      </View>
      <Text variant="bodyStrong" style={{ marginTop: 10 }}>
        {title}
      </Text>
      <Text variant="small" tone="muted" numberOfLines={1}>
        {body}
      </Text>
    </Press>
  );
}

const styles = StyleSheet.create({
  search: { flexDirection: 'row', alignItems: 'center', gap: 10, height: 52, borderRadius: radius.lg, borderWidth: 1.5, paddingHorizontal: 14, marginTop: 12 },
  result: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 10, borderBottomWidth: StyleSheet.hairlineWidth },
  thumb: { width: 52, height: 52, borderRadius: 10 },
  all: { flexDirection: 'row', alignItems: 'center', gap: 10, borderRadius: radius.md, padding: 14, marginTop: 12 },
  rowHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 26, marginBottom: 10 },
  wrap: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  recent: { flexDirection: 'row', alignItems: 'center', gap: 6, borderWidth: 1, borderRadius: radius.pill, paddingHorizontal: 12, height: 36 },
  cat: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 10, borderBottomWidth: StyleSheet.hairlineWidth },
  catIcon: { width: 40, height: 40, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  quick: { flex: 1, borderWidth: 1, borderRadius: radius.lg, padding: 14 },
});
