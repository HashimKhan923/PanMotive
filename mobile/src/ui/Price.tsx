import { StyleSheet, View } from 'react-native';

import { Text } from '@/ui/Text';
import { useTheme } from '@/theme/theme';

export const money = (n: number) => '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

type Props = { price: number; sale?: number | null; basis?: string; size?: 'md' | 'lg'; showOff?: boolean };

/** Price + basis ("each", "pair", "set") + struck-through list price when a live deal is on — like the website. */
export function Price({ price, sale, basis = 'each', size = 'md', showOff = false }: Props) {
  const { c } = useTheme();
  const onSale = sale != null && sale < price;
  const off = onSale ? Math.round((1 - sale! / Math.max(price, 0.01)) * 100) : 0;

  return (
    <View style={styles.row}>
      <Text variant={size === 'lg' ? 'priceLarge' : 'price'}>{money(onSale ? sale! : price)}</Text>
      <Text variant="small" tone="muted">
        {basis}
      </Text>
      {onSale && (
        <Text variant="small" tone="muted" style={{ textDecorationLine: 'line-through' }}>
          {money(price)}
        </Text>
      )}
      {onSale && showOff && (
        <View style={[styles.off, { backgroundColor: c.accent }]}>
          <Text variant="smallStrong" style={{ color: '#fff' }}>
            −{off}%
          </Text>
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'baseline', flexWrap: 'wrap', columnGap: 6 },
  off: { borderRadius: 999, paddingHorizontal: 8, paddingVertical: 2, alignSelf: 'center' },
});
