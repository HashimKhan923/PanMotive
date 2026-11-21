import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { StyleSheet, View } from 'react-native';
import Animated, { FadeInRight, useAnimatedStyle, useSharedValue, withDelay, withTiming } from 'react-native-reanimated';
import { useEffect } from 'react';

import { get } from '@/api/client';
import type { PageMeta, ProductDetail } from '@/api/types';
import { Button } from '@/ui/Button';
import { Icon } from '@/ui/Icon';
import { Rating } from '@/ui/Rating';
import { GUTTER, SectionTitle } from '@/ui/Section';
import { Text } from '@/ui/Text';
import { timing } from '@/theme/motion';
import { useTheme } from '@/theme/theme';
import { radius } from '@/theme/tokens';

export type Review = { id: number; rating: number; title: string | null; body: string; author: string; verified: boolean; helpful: number; seller_response: string | null; date: string };
export type Question = { id: number; body: string; author: string; date: string; answers: { id: number; body: string; is_seller: boolean; author: string; date: string }[] };

/** Star breakdown with bars that grow in, and the latest reviews. */
export function ReviewsPreview({ product: p }: { product: ProductDetail }) {
  const { c } = useTheme();
  const q = useQuery({
    queryKey: ['reviews', p.id, 1],
    queryFn: () => get<{ reviews: Review[]; meta: PageMeta; can_review: boolean }>(`/products/${p.id}/reviews`),
  });
  const total = p.reviews.count;

  return (
    <View>
      <SectionTitle title="Reviews" action={total > 3 ? 'See all' : undefined} onAction={() => router.push({ pathname: '/reviews/[id]', params: { id: String(p.id) } })} />
      <View style={[styles.box, { borderColor: c.line, backgroundColor: c.surface }]}>
        {total > 0 ? (
          <View style={{ flexDirection: 'row', gap: 18, alignItems: 'center' }}>
            <View style={{ alignItems: 'center' }}>
              <Text variant="priceLarge">{p.reviews.average.toFixed(1)}</Text>
              <Rating value={p.reviews.average} size={12} />
              <Text variant="small" tone="muted" style={{ marginTop: 2 }}>
                {total} {total === 1 ? 'review' : 'reviews'}
              </Text>
            </View>
            <View style={{ flex: 1, gap: 4 }}>
              {[5, 4, 3, 2, 1].map((s, i) => (
                <Bar key={s} star={s} share={(p.reviews.breakdown[String(s)] ?? 0) / total} delay={i * 70} />
              ))}
            </View>
          </View>
        ) : (
          <Text variant="small" tone="muted">
            No reviews yet. Buyers who receive this part can review it.
          </Text>
        )}
      </View>
      {q.data?.reviews.slice(0, 3).map((r, i) => (
        <Animated.View key={r.id} entering={FadeInRight.delay(i * 80).springify()} style={[styles.item, { borderColor: c.line }]}>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
            <Rating value={r.rating} size={12} />
            {r.verified ? (
              <Text variant="small" tone="success" style={{ fontSize: 11 }}>
                Verified purchase
              </Text>
            ) : null}
          </View>
          {r.title ? (
            <Text variant="smallStrong" style={{ marginTop: 6 }}>
              {r.title}
            </Text>
          ) : null}
          <Text variant="small" style={{ marginTop: 4 }}>
            {r.body}
          </Text>
          <Text variant="small" tone="muted" style={{ marginTop: 6, fontSize: 11 }}>
            {r.author} · {new Date(r.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
          </Text>
          {r.seller_response ? (
            <View style={[styles.reply, { backgroundColor: c.surface2 }]}>
              <Text variant="smallStrong" style={{ fontSize: 12 }}>
                Seller's reply
              </Text>
              <Text variant="small">{r.seller_response}</Text>
            </View>
          ) : null}
        </Animated.View>
      ))}
      {q.data?.can_review ? (
        <View style={{ paddingHorizontal: GUTTER, marginTop: 12 }}>
          <Button title="Write a review" kind="outline" icon="star" onPress={() => router.push({ pathname: '/review/[id]', params: { id: String(p.id), title: p.title } })} />
        </View>
      ) : null}
    </View>
  );
}

function Bar({ star, share, delay }: { star: number; share: number; delay: number }) {
  const { c } = useTheme();
  const w = useSharedValue(0);
  useEffect(() => {
    w.value = withDelay(delay, withTiming(share, timing.slow));
  }, [delay, share, w]);
  const style = useAnimatedStyle(() => ({ width: `${w.value * 100}%` }));

  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
      <Text variant="small" tone="muted" style={{ width: 10, fontSize: 11 }}>
        {star}
      </Text>
      <View style={[styles.track, { backgroundColor: c.surface2 }]}>
        <Animated.View style={[styles.fill, { backgroundColor: c.fg }, style]} />
      </View>
    </View>
  );
}

/** The newest questions with their answers, and "Ask a question". */
export function QaPreview({ product: p }: { product: ProductDetail }) {
  const { c } = useTheme();
  const q = useQuery({ queryKey: ['questions', p.id, 1], queryFn: () => get<{ questions: Question[]; meta: PageMeta }>(`/products/${p.id}/questions`) });

  return (
    <View>
      <SectionTitle
        title="Questions & answers"
        subtitle={p.questions_count ? `${p.questions_count} ${p.questions_count === 1 ? 'question' : 'questions'}` : undefined}
        action={p.questions_count > 2 ? 'See all' : undefined}
        onAction={() => router.push({ pathname: '/questions/[id]', params: { id: String(p.id) } })}
      />
      {q.data?.questions.slice(0, 2).map((x) => (
        <View key={x.id} style={[styles.item, { borderColor: c.line }]}>
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <Text variant="smallStrong">Q</Text>
            <Text variant="smallStrong" style={{ flex: 1 }}>
              {x.body}
            </Text>
          </View>
          {x.answers.slice(0, 1).map((a) => (
            <View key={a.id} style={{ flexDirection: 'row', gap: 8, marginTop: 6 }}>
              <Text variant="smallStrong" tone="muted">
                A
              </Text>
              <View style={{ flex: 1 }}>
                <Text variant="small">{a.body}</Text>
                <Text variant="small" tone="muted" style={{ fontSize: 11, marginTop: 2 }}>
                  {a.is_seller ? 'Seller' : a.author}
                </Text>
              </View>
            </View>
          ))}
          {!x.answers.length ? (
            <Text variant="small" tone="muted" style={{ marginTop: 4 }}>
              No answer yet.
            </Text>
          ) : null}
        </View>
      ))}
      <View style={{ paddingHorizontal: GUTTER, marginTop: 12 }}>
        <Button title="Ask a question" kind="outline" icon="help" onPress={() => router.push({ pathname: '/ask/[id]', params: { id: String(p.id), title: p.title, mode: 'question' } })} />
      </View>
    </View>
  );
}

export function StarIcon() {
  return <Icon name="star" size={16} />;
}

const styles = StyleSheet.create({
  box: { marginHorizontal: GUTTER, borderWidth: 1, borderRadius: radius.lg, padding: 16 },
  item: { marginHorizontal: GUTTER, paddingVertical: 14, borderBottomWidth: StyleSheet.hairlineWidth },
  reply: { marginTop: 8, borderRadius: radius.md, padding: 10, gap: 2 },
  track: { flex: 1, height: 6, borderRadius: 3, overflow: 'hidden' },
  fill: { height: 6, borderRadius: 3 },
});
