import { useQuery } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router } from 'expo-router';
import { ScrollView, StyleSheet, View } from 'react-native';
import Animated, { FadeInRight, LinearTransition } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useAddToCart } from '@/api/actions';
import { get } from '@/api/client';
import type { ProductCard } from '@/api/types';
import { usePrefs } from '@/state/prefs';
import { Button } from '@/ui/Button';
import { Icon } from '@/ui/Icon';
import { Press } from '@/ui/Press';
import { money } from '@/ui/Price';
import { Rating } from '@/ui/Rating';
import { Empty } from '@/ui/Section';
import { Text } from '@/ui/Text';
import { useTheme } from '@/theme/theme';
import { radius } from '@/theme/tokens';

type Row = ProductCard & { vehicles: string[]; vehicles_total: number; attributes: Record<string, string | null> };

const COL = 180;

/** Up to 4 parts side by side. The list lives on the phone, so it works without signing in. */
export default function CompareScreen() {
  const { c } = useTheme();
  const insets = useSafeAreaInsets();
  const ids = usePrefs((s) => s.compare);
  const toggle = usePrefs((s) => s.toggleCompare);
  const add = useAddToCart();
  const q = useQuery({
    queryKey: ['compare', ids.join(',')],
    queryFn: () => get<{ products: Row[]; attribute_names: string[] }>('/compare', { ids: ids.join(',') }),
    enabled: ids.length > 0,
  });
  const rows = q.data?.products ?? [];

  const lines: [string, (p: Row) => React.ReactNode][] = [
    ['Price', (p) => <Text variant="bodyStrong">{money(p.effective_price)}</Text>],
    ['Rating', (p) => (p.rating > 0 ? <Rating value={p.rating} count={p.rating_count} /> : <Text variant="small" tone="muted">—</Text>)],
    ['Fits', (p) => <Text variant="small">{p.fits_vehicle ?? '—'}</Text>],
    ['Fits your vehicle', (p) => <Text variant="smallStrong" tone={p.fits_garage ? 'success' : 'muted'}>{p.fits_garage ? 'Yes' : p.fits_garage === false ? 'No' : '—'}</Text>],
    ['Condition', (p) => <Text variant="small" style={{ textTransform: 'capitalize' }}>{p.condition}</Text>],
    ['Part number', (p) => <Text variant="small">{p.part_number ?? '—'}</Text>],
    ['Availability', (p) => <Text variant="small" tone={p.in_stock ? 'success' : 'error'}>{p.in_stock ? 'In stock' : 'Out of stock'}</Text>],
    ['Sold by', (p) => <Text variant="small">{p.seller?.name ?? '—'}</Text>],
    ...(q.data?.attribute_names ?? []).map((n): [string, (p: Row) => React.ReactNode] => [n, (p) => <Text variant="small">{p.attributes[n] ?? '—'}</Text>]),
  ];

  return (
    <View style={{ flex: 1, backgroundColor: c.bg, paddingTop: insets.top }}>
      <View style={styles.top}>
        <Press onPress={() => router.back()} style={styles.iconBtn} accessibilityLabel="Back">
          <Icon name="arrow-left" size={22} />
        </Press>
        <Text variant="h3">Compare</Text>
        <View style={styles.iconBtn} />
      </View>
      {!ids.length ? (
        <Empty icon="grid" title="Nothing to compare yet" body="Tap Compare on up to 4 parts to see them side by side.">
          <Button title="Browse parts" onPress={() => router.navigate('/shop')} />
        </Empty>
      ) : (
        <ScrollView horizontal contentContainerStyle={{ paddingHorizontal: 16 }}>
          <View>
            <View style={{ flexDirection: 'row' }}>
              <View style={{ width: 110 }} />
              {rows.map((p, i) => (
                <Animated.View key={p.id} entering={FadeInRight.delay(i * 80).springify()} layout={LinearTransition.springify()} style={[styles.col, { borderColor: c.line, backgroundColor: c.surface }]}>
                  <Press onPress={() => toggle(p.id)} style={styles.remove} accessibilityLabel="Remove from compare">
                    <Icon name="x" size={16} />
                  </Press>
                  <Image source={p.image} style={[styles.img, { backgroundColor: c.surface2 }]} contentFit="cover" />
                  <Text variant="smallStrong" numberOfLines={3} style={{ marginTop: 8 }}>
                    {p.title}
                  </Text>
                  <View style={{ flexDirection: 'row', gap: 6, marginTop: 10 }}>
                    <Button title="View" size="sm" kind="outline" style={{ flex: 1 }} onPress={() => router.push({ pathname: '/product/[slug]', params: { slug: p.slug } })} />
                    {p.quick_add ? <Button title="Add" size="sm" style={{ flex: 1 }} onPress={() => add.mutate({ productId: p.id, image: p.image })} /> : null}
                  </View>
                </Animated.View>
              ))}
            </View>
            {lines.map(([label, cell], r) => (
              <View key={label} style={[styles.row, { backgroundColor: r % 2 ? 'transparent' : c.surface2 }]}>
                <Text variant="smallStrong" tone="muted" style={{ width: 110 }}>
                  {label}
                </Text>
                {rows.map((p) => (
                  <View key={p.id} style={{ width: COL, paddingHorizontal: 10 }}>
                    {cell(p)}
                  </View>
                ))}
              </View>
            ))}
          </View>
        </ScrollView>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 8, height: 52 },
  iconBtn: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  col: { width: COL - 8, marginHorizontal: 4, borderWidth: 1, borderRadius: radius.lg, padding: 10, marginBottom: 12 },
  remove: { position: 'absolute', top: 6, right: 6, zIndex: 2, width: 28, height: 28, borderRadius: 14, backgroundColor: 'rgba(21,25,30,0.6)', alignItems: 'center', justifyContent: 'center' },
  img: { width: '100%', aspectRatio: 1, borderRadius: 12 },
  row: { flexDirection: 'row', alignItems: 'center', paddingVertical: 12, paddingHorizontal: 6, borderRadius: 8 },
});
