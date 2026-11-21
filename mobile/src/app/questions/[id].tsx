import { useInfiniteQuery } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useMemo } from 'react';
import { FlatList, StyleSheet, View } from 'react-native';
import Animated, { FadeInDown } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { get } from '@/api/client';
import type { PageMeta } from '@/api/types';
import type { Question } from '@/features/product/Social';
import { Button } from '@/ui/Button';
import { Icon } from '@/ui/Icon';
import { Press } from '@/ui/Press';
import { Text } from '@/ui/Text';
import { useTheme } from '@/theme/theme';

/** All questions and answers for a part. */
export default function QuestionsScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { c } = useTheme();
  const insets = useSafeAreaInsets();
  const q = useInfiniteQuery({
    queryKey: ['questions-all', id],
    initialPageParam: 1,
    queryFn: ({ pageParam }) => get<{ questions: Question[]; meta: PageMeta }>(`/products/${id}/questions`, { page: pageParam }),
    getNextPageParam: (l) => (l.meta.page < l.meta.last_page ? l.meta.page + 1 : undefined),
  });
  const rows = useMemo(() => q.data?.pages.flatMap((p) => p.questions) ?? [], [q.data]);

  return (
    <View style={{ flex: 1, backgroundColor: c.bg, paddingTop: insets.top }}>
      <View style={styles.top}>
        <Press onPress={() => router.back()} style={styles.iconBtn} accessibilityLabel="Back">
          <Icon name="arrow-left" size={22} />
        </Press>
        <Text variant="h3">Questions & answers</Text>
        <View style={styles.iconBtn} />
      </View>
      <FlatList
        data={rows}
        keyExtractor={(x) => String(x.id)}
        onEndReached={() => q.hasNextPage && void q.fetchNextPage()}
        contentContainerStyle={{ padding: 16, paddingBottom: insets.bottom + 100 }}
        renderItem={({ item: x, index }) => (
          <Animated.View entering={FadeInDown.delay((index % 10) * 40)} style={[styles.item, { borderColor: c.line }]}>
            <View style={{ flexDirection: 'row', gap: 8 }}>
              <Text variant="bodyStrong">Q</Text>
              <Text variant="bodyStrong" style={{ flex: 1 }}>
                {x.body}
              </Text>
            </View>
            <Text variant="small" tone="muted" style={{ marginLeft: 22, fontSize: 11 }}>
              {x.author} · {new Date(x.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}
            </Text>
            {x.answers.map((a) => (
              <View key={a.id} style={{ flexDirection: 'row', gap: 8, marginTop: 8 }}>
                <Text variant="bodyStrong" tone="muted">
                  A
                </Text>
                <View style={{ flex: 1 }}>
                  <Text variant="body">{a.body}</Text>
                  <Text variant="small" tone={a.is_seller ? 'success' : 'muted'} style={{ fontSize: 11 }}>
                    {a.is_seller ? 'Seller' : a.author}
                  </Text>
                </View>
              </View>
            ))}
            {!x.answers.length ? (
              <Text variant="small" tone="muted" style={{ marginTop: 6, marginLeft: 22 }}>
                No answer yet.
              </Text>
            ) : null}
          </Animated.View>
        )}
      />
      <View style={{ position: 'absolute', left: 16, right: 16, bottom: insets.bottom + 16 }}>
        <Button title="Ask a question" icon="help" size="lg" onPress={() => router.push({ pathname: '/ask/[id]', params: { id, mode: 'question' } })} />
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 8, height: 52 },
  iconBtn: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  item: { paddingVertical: 14, borderBottomWidth: StyleSheet.hairlineWidth },
});
