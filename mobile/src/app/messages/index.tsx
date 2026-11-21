import { useQuery } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router } from 'expo-router';
import { FlatList, StyleSheet, View } from 'react-native';
import Animated, { FadeInDown } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { get } from '@/api/client';
import { Icon } from '@/ui/Icon';
import { Press } from '@/ui/Press';
import { Empty, GUTTER } from '@/ui/Section';
import { Skeleton } from '@/ui/Skeleton';
import { Text } from '@/ui/Text';
import { STAGGER } from '@/theme/motion';
import { useTheme } from '@/theme/theme';
import { palette, radius } from '@/theme/tokens';

type Convo = { id: number; seller: { name: string; slug: string; logo: string | null } | null; subject: string | null; last: string | null; last_at: string | null; unread: number };

export function ago(iso: string | null) {
  if (!iso) return '';
  const s = (Date.now() - new Date(iso).getTime()) / 1000;
  if (s < 60) return 'now';
  if (s < 3600) return `${Math.floor(s / 60)}m`;
  if (s < 86400) return `${Math.floor(s / 3600)}h`;
  if (s < 604800) return `${Math.floor(s / 86400)}d`;
  return new Date(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

export default function MessagesScreen() {
  const { c } = useTheme();
  const insets = useSafeAreaInsets();
  const q = useQuery({ queryKey: ['messages'], queryFn: () => get<{ conversations: Convo[] }>('/messages').then((r) => r.conversations), refetchInterval: 30_000 });

  return (
    <View style={{ flex: 1, backgroundColor: c.bg, paddingTop: insets.top }}>
      <View style={styles.top}>
        <Press onPress={() => router.back()} style={styles.iconBtn} accessibilityLabel="Back">
          <Icon name="arrow-left" size={22} />
        </Press>
        <Text variant="h3">Messages</Text>
        <View style={styles.iconBtn} />
      </View>
      {q.isLoading ? (
        <View style={{ padding: GUTTER, gap: 12 }}>
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} height={68} radius={radius.lg} />
          ))}
        </View>
      ) : (
        <FlatList
          data={q.data ?? []}
          keyExtractor={(x) => String(x.id)}
          onRefresh={() => void q.refetch()}
          refreshing={q.isRefetching}
          contentContainerStyle={{ paddingBottom: insets.bottom + 40 }}
          ListEmptyComponent={<Empty icon="message" title="No messages yet" body="Ask a seller about a part from its page, or contact them from an order." />}
          renderItem={({ item: m, index }) => (
            <Animated.View entering={FadeInDown.delay(Math.min(index, 10) * STAGGER).springify()}>
              <Press onPress={() => router.push({ pathname: '/messages/[id]', params: { id: String(m.id) } })} scaleTo={0.98} style={[styles.row, { borderBottomColor: c.line }]}>
                {m.seller?.logo ? (
                  <Image source={m.seller.logo} style={[styles.avatar, { backgroundColor: c.surface }]} contentFit="contain" />
                ) : (
                  <View style={[styles.avatar, { backgroundColor: palette.charcoal, alignItems: 'center', justifyContent: 'center' }]}>
                    <Text variant="h3" style={{ color: '#fff' }}>
                      {m.seller?.name.charAt(0).toUpperCase() ?? '?'}
                    </Text>
                  </View>
                )}
                <View style={{ flex: 1 }}>
                  <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                    <Text variant="bodyStrong" numberOfLines={1} style={{ flex: 1 }}>
                      {m.seller?.name ?? 'Seller'}
                    </Text>
                    <Text variant="small" tone="muted" style={{ fontSize: 11 }}>
                      {ago(m.last_at)}
                    </Text>
                  </View>
                  {m.subject ? (
                    <Text variant="small" tone="muted" numberOfLines={1} style={{ fontSize: 12 }}>
                      {m.subject}
                    </Text>
                  ) : null}
                  <Text variant={m.unread ? 'smallStrong' : 'small'} tone={m.unread ? 'fg' : 'muted'} numberOfLines={1}>
                    {m.last}
                  </Text>
                </View>
                {m.unread ? <View style={styles.dot} /> : null}
              </Press>
            </Animated.View>
          )}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 8, height: 52 },
  iconBtn: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: GUTTER, paddingVertical: 12, borderBottomWidth: StyleSheet.hairlineWidth },
  avatar: { width: 48, height: 48, borderRadius: 14, overflow: 'hidden' },
  dot: { width: 10, height: 10, borderRadius: 5, backgroundColor: palette.red },
});
