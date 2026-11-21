import { FlashList } from '@shopify/flash-list';
import { type ReactNode } from 'react';
import { StyleSheet, View, useWindowDimensions } from 'react-native';

import type { ProductCard as Card } from '@/api/types';
import { Icon } from '@/ui/Icon';
import { Press } from '@/ui/Press';
import { ProductCard } from '@/ui/ProductCard';
import { CardSkeleton } from '@/ui/Skeleton';
import { Text } from '@/ui/Text';
import { useTheme } from '@/theme/theme';
import { palette, space } from '@/theme/tokens';

export const GUTTER = 16;

/** A section heading with the brand's short red forward-cut bar above it (website .pm-cut-bar). */
export function SectionTitle({ title, subtitle, action, onAction }: { title: string; subtitle?: string; action?: string; onAction?: () => void }) {
  return (
    <View style={styles.head}>
      <View style={{ flex: 1 }}>
        <View style={styles.cutBar} />
        <Text variant="h2">{title}</Text>
        {subtitle ? (
          <Text variant="small" tone="muted" style={{ marginTop: 2 }}>
            {subtitle}
          </Text>
        ) : null}
      </View>
      {action && onAction ? (
        <Press onPress={onAction} haptic="selection" style={styles.action} accessibilityLabel={action}>
          <Text variant="smallStrong" style={{ textDecorationLine: 'underline' }}>
            {action}
          </Text>
          <Icon name="arrow-right" size={16} />
        </Press>
      ) : null}
    </View>
  );
}

/** A sideways-scrolling row of product cards. */
export function ProductRail({ products, loading }: { products?: Card[]; loading?: boolean }) {
  const { width } = useWindowDimensions();
  const cardW = Math.min(200, Math.round((width - GUTTER * 2 - 12) / 2.2));

  if (loading && !products) {
    return (
      <View style={{ flexDirection: 'row', gap: 12, paddingHorizontal: GUTTER }}>
        <CardSkeleton width={cardW} />
        <CardSkeleton width={cardW} />
      </View>
    );
  }
  if (!products?.length) return null;

  return (
    <FlashList
      horizontal
      data={products}
      keyExtractor={(p) => String(p.id)}
      showsHorizontalScrollIndicator={false}
      contentContainerStyle={{ paddingHorizontal: GUTTER }}
      ItemSeparatorComponent={() => <View style={{ width: 12 }} />}
      renderItem={({ item, index }) => <ProductCard product={item} width={cardW} index={index} />}
    />
  );
}

/** A two-column grid of product cards (inside a ScrollView). */
export function ProductGrid({ products, loading, count = 4 }: { products?: Card[]; loading?: boolean; count?: number }) {
  const { width } = useWindowDimensions();
  const cardW = Math.floor((width - GUTTER * 2 - 12) / 2);

  return (
    <View style={styles.grid}>
      {loading && !products
        ? Array.from({ length: count }).map((_, i) => <CardSkeleton key={i} width={cardW} />)
        : products?.map((p, i) => <ProductCard key={p.id} product={p} width={cardW} index={i} />)}
    </View>
  );
}

/** Space between sections, and the padding that keeps content clear of the floating tab bar. */
export function Gap({ size = space.xxxl }: { size?: number }) {
  return <View style={{ height: size }} />;
}

export function Empty({ icon, title, body, children }: { icon: string; title: string; body?: string; children?: ReactNode }) {
  const { c } = useTheme();

  return (
    <View style={styles.empty}>
      <View style={[styles.emptyIcon, { backgroundColor: c.surface2 }]}>
        <Icon name={icon} size={32} color={c.muted} />
      </View>
      <Text variant="h3" center>
        {title}
      </Text>
      {body ? (
        <Text variant="small" tone="muted" center style={{ maxWidth: 280 }}>
          {body}
        </Text>
      ) : null}
      {children}
    </View>
  );
}

const styles = StyleSheet.create({
  head: { flexDirection: 'row', alignItems: 'flex-end', paddingHorizontal: GUTTER, marginBottom: 14 },
  cutBar: { width: 44, height: 6, backgroundColor: palette.red, transform: [{ skewX: '-16deg' }], marginBottom: 10, marginLeft: 2 },
  action: { flexDirection: 'row', alignItems: 'center', gap: 4, paddingVertical: 6 },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12, paddingHorizontal: GUTTER },
  empty: { alignItems: 'center', gap: 10, paddingVertical: 48, paddingHorizontal: 24 },
  emptyIcon: { width: 72, height: 72, borderRadius: 36, alignItems: 'center', justifyContent: 'center', marginBottom: 6 },
});
