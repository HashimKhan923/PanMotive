import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { StyleSheet, View } from 'react-native';
import Animated, { FadeInDown } from 'react-native-reanimated';

import { get } from '@/api/client';
import type { Category } from '@/api/types';
import { FormScreen } from '@/ui/FormScreen';
import { Icon } from '@/ui/Icon';
import { Press } from '@/ui/Press';
import { Skeleton } from '@/ui/Skeleton';
import { Text } from '@/ui/Text';
import { STAGGER } from '@/theme/motion';
import { useTheme } from '@/theme/theme';
import { radius } from '@/theme/tokens';

export default function CategoriesScreen() {
  const { c } = useTheme();
  const q = useQuery({ queryKey: ['categories'], queryFn: () => get<{ categories: Category[] }>('/categories').then((r) => r.categories) });

  return (
    <FormScreen title="All categories">
      {!q.data
        ? [0, 1, 2, 3, 4].map((i) => <Skeleton key={i} height={70} radius={radius.lg} />)
        : q.data.map((cat, i) => (
            <Animated.View key={cat.id} entering={FadeInDown.delay(i * STAGGER).springify()} style={[styles.card, { backgroundColor: c.surface, borderColor: c.line }]}>
              <Press onPress={() => router.push({ pathname: '/shop', params: { category: cat.slug } })} scaleTo={0.98} style={styles.head}>
                <View style={[styles.icon, { backgroundColor: c.surface2 }]}>
                  <Icon name={cat.icon} size={22} />
                </View>
                <View style={{ flex: 1 }}>
                  <Text variant="bodyStrong">{cat.name}</Text>
                  {cat.count != null ? (
                    <Text variant="small" tone="muted">
                      {cat.count} parts
                    </Text>
                  ) : null}
                </View>
                <Icon name="chevron-right" size={18} color={c.muted} />
              </Press>
              {cat.children.length ? (
                <View style={styles.kids}>
                  {cat.children.map((k) => (
                    <Press key={k.id} onPress={() => router.push({ pathname: '/shop', params: { category: k.slug } })} haptic="selection" style={[styles.kid, { borderColor: c.line }]}>
                      <Text variant="small">{k.name}</Text>
                    </Press>
                  ))}
                </View>
              ) : null}
            </Animated.View>
          ))}
    </FormScreen>
  );
}

const styles = StyleSheet.create({
  card: { borderWidth: 1, borderRadius: radius.lg, overflow: 'hidden' },
  head: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 12 },
  icon: { width: 44, height: 44, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  kids: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, paddingHorizontal: 12, paddingBottom: 12 },
  kid: { borderWidth: 1, borderRadius: 999, paddingHorizontal: 10, paddingVertical: 5 },
});
