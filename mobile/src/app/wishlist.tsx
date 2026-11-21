import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { ScrollView, StyleSheet, View, useWindowDimensions } from 'react-native';
import Animated, { FadeInDown, LinearTransition } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { get } from '@/api/client';
import type { ProductCard as Card } from '@/api/types';
import { Button } from '@/ui/Button';
import { Icon } from '@/ui/Icon';
import { Press } from '@/ui/Press';
import { money } from '@/ui/Price';
import { ProductCard } from '@/ui/ProductCard';
import { Empty, GUTTER } from '@/ui/Section';
import { CardSkeleton } from '@/ui/Skeleton';
import { Text } from '@/ui/Text';
import { useTheme } from '@/theme/theme';

type Saved = Card & { price_when_saved: number | null; dropped_by: number | null };

/** Saved parts, with "price dropped" shown where it's cheaper than when you saved it. */
export default function WishlistScreen() {
  const { c } = useTheme();
  const { width } = useWindowDimensions();
  const insets = useSafeAreaInsets();
  const q = useQuery({ queryKey: ['wishlist'], queryFn: () => get<{ products: Saved[] }>('/wishlist').then((r) => r.products) });
  const cardW = Math.floor((width - GUTTER * 2 - 12) / 2);
  const dropped = q.data?.filter((p) => p.dropped_by).length ?? 0;

  return (
    <View style={{ flex: 1, backgroundColor: c.bg, paddingTop: insets.top }}>
      <View style={styles.top}>
        <Press onPress={() => router.back()} style={styles.iconBtn} accessibilityLabel="Back">
          <Icon name="arrow-left" size={22} />
        </Press>
        <Text variant="h3">Saved parts</Text>
        <View style={styles.iconBtn} />
      </View>
      <ScrollView contentContainerStyle={{ padding: GUTTER, paddingBottom: insets.bottom + 40 }}>
        {dropped ? (
          <Animated.View entering={FadeInDown.springify()} style={[styles.drop, { backgroundColor: c.successBg }]}>
            <Icon name="price" size={18} color={c.success} />
            <Text variant="smallStrong" style={{ color: c.success, flex: 1 }}>
              {dropped} saved {dropped === 1 ? 'part has' : 'parts have'} dropped in price.
            </Text>
          </Animated.View>
        ) : null}
        {q.isLoading ? (
          <View style={styles.grid}>
            {[0, 1, 2, 3].map((i) => (
              <CardSkeleton key={i} width={cardW} />
            ))}
          </View>
        ) : !q.data?.length ? (
          <Empty icon="heart" title="Nothing saved yet" body="Tap the heart on any part to keep it here. We'll tell you if the price drops.">
            <Button title="Browse parts" onPress={() => router.navigate('/shop')} />
          </Empty>
        ) : (
          <View style={styles.grid}>
            {q.data.map((p, i) => (
              <Animated.View key={p.id} layout={LinearTransition.springify()}>
                <ProductCard product={p} width={cardW} index={i} />
                {p.dropped_by ? (
                  <View style={[styles.badge, { backgroundColor: c.success }]}>
                    <Text variant="smallStrong" style={{ color: c.bg, fontSize: 11 }}>
                      ↓ {money(p.dropped_by)} since saved
                    </Text>
                  </View>
                ) : null}
              </Animated.View>
            ))}
          </View>
        )}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 8, height: 52 },
  iconBtn: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  drop: { flexDirection: 'row', alignItems: 'center', gap: 8, borderRadius: 12, padding: 12, marginBottom: 14 },
  badge: { position: 'absolute', top: 44, left: 8, borderRadius: 999, paddingHorizontal: 8, paddingVertical: 2 },
});
