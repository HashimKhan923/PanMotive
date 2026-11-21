import { FlashList } from '@shopify/flash-list';
import { useInfiniteQuery } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useMemo, useState } from 'react';
import { ScrollView, StyleSheet, TextInput, View, useWindowDimensions } from 'react-native';
import Animated, { FadeIn, FadeInDown, LinearTransition, ZoomIn, ZoomOut } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { get } from '@/api/client';
import type { ProductList } from '@/api/types';
import { usePrefs } from '@/state/prefs';
import { useGarage } from '@/state/session';
import { Button } from '@/ui/Button';
import { Icon } from '@/ui/Icon';
import { haptic, Press } from '@/ui/Press';
import { ProductCard } from '@/ui/ProductCard';
import { Empty, GUTTER } from '@/ui/Section';
import { Sheet } from '@/ui/Sheet';
import { CardSkeleton } from '@/ui/Skeleton';
import { Text } from '@/ui/Text';
import { useTheme } from '@/theme/theme';
import { font, palette, radius } from '@/theme/tokens';

type Filters = { q: string; category: string; condition: string; make: string; model: string; vehicleId: string; sort: string };

const SORTS: [string, string][] = [
  ['', 'Best match'],
  ['newest', 'Newest'],
  ['price_asc', 'Price: low to high'],
  ['price_desc', 'Price: high to low'],
];

/** Search results and category browsing — the same search, filters and order as the website's shop. */
export default function ShopScreen() {
  const params = useLocalSearchParams<Partial<Filters>>();
  const { c } = useTheme();
  const { width } = useWindowDimensions();
  const insets = useSafeAreaInsets();
  const vehicle = useGarage((s) => s.vehicle);
  const addSearch = usePrefs((s) => s.addSearch);

  const [f, setF] = useState<Filters>({
    q: params.q ?? '',
    category: params.category ?? '',
    condition: params.condition ?? '',
    make: params.make ?? '',
    model: params.model ?? '',
    vehicleId: params.vehicleId ?? '',
    sort: params.sort ?? '',
  });
  const [text, setText] = useState(f.q);
  const [sheet, setSheet] = useState<'filters' | 'sort' | null>(null);
  const cardW = Math.floor((width - GUTTER * 2 - 12) / 2);

  const q = useInfiniteQuery({
    queryKey: ['shop', f, vehicle?.id ?? null],
    initialPageParam: 1,
    queryFn: ({ pageParam, signal }) => get<ProductList>('/products', { ...f, page: pageParam, per_page: 24, facets: pageParam === 1 ? 1 : 0 }, signal),
    getNextPageParam: (last) => (last.meta.page < last.meta.last_page ? last.meta.page + 1 : undefined),
  });

  const first = q.data?.pages[0];
  const products = useMemo(() => q.data?.pages.flatMap((p) => p.products) ?? [], [q.data]);
  const facets = first?.facets;
  const total = first?.meta.total ?? 0;
  const title = facets?.selected_category?.name ?? (f.q ? `"${f.q}"` : 'All parts');

  const set = (patch: Partial<Filters>) => {
    haptic('selection');
    setF((old) => ({ ...old, ...patch }));
  };

  const chips: { key: keyof Filters; label: string }[] = [];
  if (f.q) chips.push({ key: 'q', label: `"${f.q}"` });
  if (f.category) chips.push({ key: 'category', label: facets?.selected_category?.name ?? f.category });
  if (f.condition) chips.push({ key: 'condition', label: f.condition.charAt(0).toUpperCase() + f.condition.slice(1) });
  if (f.make) chips.push({ key: 'make', label: [f.make, f.model].filter(Boolean).join(' ') });
  if (f.vehicleId) chips.push({ key: 'vehicleId', label: vehicle && String(vehicle.id) === f.vehicleId ? `Fits ${vehicle.label}` : 'Selected vehicle' });

  return (
    <View style={{ flex: 1, backgroundColor: c.bg, paddingTop: insets.top }}>
      {/* Search bar */}
      <View style={styles.top}>
        <Press onPress={() => router.back()} style={styles.iconBtn} accessibilityLabel="Back">
          <Icon name="arrow-left" size={22} />
        </Press>
        <View style={[styles.search, { backgroundColor: c.surface, borderColor: c.line }]}>
          <Icon name="search" size={18} color={c.muted} />
          <TextInput
            value={text}
            onChangeText={setText}
            onSubmitEditing={() => {
              addSearch(text);
              set({ q: text.trim() });
            }}
            returnKeyType="search"
            placeholder="Search parts, vehicles, part numbers"
            placeholderTextColor={c.muted}
            style={{ flex: 1, color: c.fg, fontFamily: font.regular, fontSize: 15 }}
          />
          {text ? (
            <Press onPress={() => setText('')} accessibilityLabel="Clear search" style={{ padding: 4 }}>
              <Icon name="x" size={16} color={c.muted} />
            </Press>
          ) : null}
        </View>
      </View>

      {/* Title, count, sort + filters */}
      <View style={styles.head}>
        <View style={{ flex: 1 }}>
          <Text variant="h2" numberOfLines={1}>
            {title}
          </Text>
          <Animated.Text key={total} entering={FadeIn} style={{ color: c.muted, fontFamily: font.regular, fontSize: 13 }}>
            {q.isLoading ? 'Searching…' : `${total.toLocaleString()} ${total === 1 ? 'result' : 'results'}`}
          </Animated.Text>
        </View>
        <Press onPress={() => setSheet('sort')} style={[styles.pillBtn, { borderColor: c.line }]} accessibilityLabel="Sort">
          <Icon name="list" size={16} />
          <Text variant="smallStrong">Sort</Text>
        </Press>
        <Press onPress={() => setSheet('filters')} style={[styles.pillBtn, { borderColor: c.line }]} accessibilityLabel="Filters">
          <Icon name="filter" size={16} />
          <Text variant="smallStrong">Filters</Text>
          {chips.length ? (
            <View style={styles.count}>
              <Text variant="smallStrong" style={{ color: '#fff', fontSize: 10, lineHeight: 12 }}>
                {chips.length}
              </Text>
            </View>
          ) : null}
        </Press>
      </View>

      {/* Removable chips + "Fits my vehicle" */}
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips} style={{ flexGrow: 0 }}>
        {vehicle && !f.vehicleId ? (
          <Press onPress={() => set({ vehicleId: String(vehicle.id) })} style={[styles.chip, { borderColor: palette.red }]}>
            <Icon name="vehicle" size={16} />
            <Text variant="smallStrong">Fits my {vehicle.model?.name ?? 'vehicle'}</Text>
          </Press>
        ) : null}
        {chips.map((ch) => (
          <Animated.View key={ch.key} entering={ZoomIn.springify()} exiting={ZoomOut.duration(150)} layout={LinearTransition.springify()}>
            <Press onPress={() => set(ch.key === 'make' ? { make: '', model: '' } : { [ch.key]: '' })} style={[styles.chip, { backgroundColor: c.fg, borderColor: c.fg }]} accessibilityLabel={`Remove ${ch.label}`}>
              <Text variant="smallStrong" style={{ color: c.bg }}>
                {ch.label}
              </Text>
              <Icon name="x" size={14} color={c.bg} />
            </Press>
          </Animated.View>
        ))}
      </ScrollView>

      {first?.notice ? (
        <Animated.View entering={FadeInDown} style={[styles.notice, { backgroundColor: c.surface2 }]}>
          <Icon name="sparkles" size={16} />
          <Text variant="small" style={{ flex: 1 }}>
            {first.notice}
          </Text>
        </Animated.View>
      ) : null}

      {q.isLoading ? (
        <View style={styles.skeletons}>
          {Array.from({ length: 6 }).map((_, i) => (
            <CardSkeleton key={i} width={cardW} />
          ))}
        </View>
      ) : products.length === 0 ? (
        <Empty icon="search" title="No parts found" body="Try fewer words, a part number, or remove a filter.">
          {chips.length ? <Button title="Clear filters" kind="outline" onPress={() => setF({ q: '', category: '', condition: '', make: '', model: '', vehicleId: '', sort: f.sort })} /> : null}
        </Empty>
      ) : (
        <FlashList
          data={products}
          numColumns={2}
          keyExtractor={(p) => String(p.id)}
          contentContainerStyle={{ paddingHorizontal: GUTTER - 6, paddingBottom: insets.bottom + 40 }}
          renderItem={({ item, index }) => (
            <View style={{ paddingHorizontal: 6, paddingBottom: 12 }}>
              <ProductCard product={item} width={cardW} index={index % 24} />
            </View>
          )}
          onEndReached={() => q.hasNextPage && !q.isFetchingNextPage && void q.fetchNextPage()}
          onEndReachedThreshold={0.6}
          onRefresh={() => void q.refetch()}
          refreshing={q.isRefetching && !q.isFetchingNextPage}
          ListFooterComponent={q.isFetchingNextPage ? <View style={{ flexDirection: 'row', gap: 12, paddingHorizontal: 6 }}><CardSkeleton width={cardW} /><CardSkeleton width={cardW} /></View> : null}
        />
      )}

      <Sheet open={sheet === 'sort'} onClose={() => setSheet(null)} title="Sort by">
        <View style={{ paddingHorizontal: 16, gap: 8 }}>
          {SORTS.map(([key, label]) => (
            <Press
              key={key}
              onPress={() => {
                set({ sort: key });
                setSheet(null);
              }}
              style={[styles.option, { borderColor: f.sort === key ? palette.red : c.line, backgroundColor: c.surface }]}>
              <Text variant="bodyStrong">{label}</Text>
              {f.sort === key ? <Icon name="check" size={18} /> : null}
            </Press>
          ))}
        </View>
      </Sheet>

      <Sheet
        open={sheet === 'filters'}
        onClose={() => setSheet(null)}
        title="Filters"
        footer={
          <>
            <Button title="Clear all" kind="outline" style={{ flex: 1 }} onPress={() => setF({ q: f.q, category: '', condition: '', make: '', model: '', vehicleId: '', sort: f.sort })} />
            <Button title={`Show ${total.toLocaleString()} results`} style={{ flex: 1.6 }} onPress={() => setSheet(null)} />
          </>
        }>
        <ScrollView contentContainerStyle={{ paddingHorizontal: 16, paddingBottom: 16 }}>
          {vehicle ? (
            <Press
              onPress={() => set({ vehicleId: f.vehicleId ? '' : String(vehicle.id) })}
              style={[styles.option, { borderColor: f.vehicleId ? palette.red : c.line, backgroundColor: c.surface }]}>
              <View style={{ flex: 1 }}>
                <Text variant="bodyStrong">Fits my vehicle</Text>
                <Text variant="small" tone="muted">
                  {vehicle.label}
                </Text>
              </View>
              <Toggle on={!!f.vehicleId} />
            </Press>
          ) : (
            <Button title="Add your vehicle to filter by fit" kind="outline" icon="vehicle" onPress={() => router.push('/garage')} />
          )}

          <Text variant="smallStrong" style={styles.group}>
            Category
          </Text>
          <View style={styles.wrap}>
            <FilterChip label="All" on={!f.category} onPress={() => set({ category: '' })} />
            {facets?.categories.map((cat) => (
              <View key={cat.id} style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
                <FilterChip label={cat.name} count={cat.count ?? undefined} on={f.category === cat.slug} onPress={() => set({ category: cat.slug })} />
                {cat.children.map((k) => (
                  <FilterChip key={k.id} label={k.name} count={k.count} on={f.category === k.slug} onPress={() => set({ category: k.slug })} sub />
                ))}
              </View>
            ))}
          </View>

          <Text variant="smallStrong" style={styles.group}>
            Condition
          </Text>
          <View style={styles.wrap}>
            <FilterChip label="Any" on={!f.condition} onPress={() => set({ condition: '' })} />
            {(['new', 'used', 'refurbished'] as const).map((k) => (
              <FilterChip key={k} label={k.charAt(0).toUpperCase() + k.slice(1)} count={facets?.conditions?.[k] ?? 0} on={f.condition === k} onPress={() => set({ condition: k })} />
            ))}
          </View>
        </ScrollView>
      </Sheet>
    </View>
  );
}

function FilterChip({ label, count, on, onPress, sub }: { label: string; count?: number; on: boolean; onPress: () => void; sub?: boolean }) {
  const { c } = useTheme();
  const disabled = count === 0 && !on;

  return (
    <Press
      onPress={disabled ? undefined : onPress}
      haptic={false}
      scaleTo={0.94}
      accessibilityState={{ selected: on, disabled }}
      style={[styles.fchip, { borderColor: on ? palette.red : c.line, backgroundColor: on ? 'rgba(229,27,43,0.14)' : c.surface, opacity: disabled ? 0.45 : 1, marginLeft: sub ? 8 : 0 }]}>
      <Text variant="smallStrong">{label}</Text>
      {count != null ? (
        <Text variant="small" tone="muted" style={{ fontSize: 11 }}>
          {count}
        </Text>
      ) : null}
    </Press>
  );
}

function Toggle({ on }: { on: boolean }) {
  const { c } = useTheme();
  return (
    <View style={[styles.toggle, { backgroundColor: on ? palette.red : c.surface2 }]}>
      <Animated.View layout={LinearTransition.springify().damping(16)} style={[styles.knob, { alignSelf: on ? 'flex-end' : 'flex-start' }]} />
    </View>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', gap: 6, paddingHorizontal: 8, paddingVertical: 6 },
  iconBtn: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  search: { flex: 1, flexDirection: 'row', alignItems: 'center', gap: 8, height: 46, borderRadius: radius.pill, borderWidth: 1, paddingHorizontal: 14, marginRight: 8 },
  head: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: GUTTER, paddingTop: 8 },
  pillBtn: { flexDirection: 'row', alignItems: 'center', gap: 6, borderWidth: 1, borderRadius: radius.pill, paddingHorizontal: 12, height: 38 },
  count: { minWidth: 18, height: 18, borderRadius: 9, backgroundColor: palette.red, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 4 },
  chips: { gap: 8, paddingHorizontal: GUTTER, paddingVertical: 12 },
  chip: { flexDirection: 'row', alignItems: 'center', gap: 6, borderWidth: 1.5, borderRadius: radius.pill, paddingHorizontal: 12, height: 34 },
  notice: { marginHorizontal: GUTTER, marginBottom: 10, borderRadius: radius.md, padding: 10, flexDirection: 'row', gap: 8, alignItems: 'center' },
  skeletons: { flexDirection: 'row', flexWrap: 'wrap', gap: 12, paddingHorizontal: GUTTER },
  option: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', borderWidth: 1.5, borderRadius: radius.md, padding: 14 },
  group: { marginTop: 20, marginBottom: 10 },
  wrap: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  fchip: { flexDirection: 'row', alignItems: 'center', gap: 6, borderWidth: 1.5, borderRadius: radius.pill, paddingHorizontal: 12, height: 36 },
  toggle: { width: 50, height: 30, borderRadius: 15, padding: 3, justifyContent: 'center' },
  knob: { width: 24, height: 24, borderRadius: 12, backgroundColor: '#fff' },
});
