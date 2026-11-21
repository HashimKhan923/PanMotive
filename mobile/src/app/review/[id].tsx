import { useQueryClient } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';
import Svg, { Path } from 'react-native-svg';
import Animated, { useAnimatedStyle, useSharedValue, withSequence, withSpring } from 'react-native-reanimated';

import { ApiError, post } from '@/api/client';
import { Button } from '@/ui/Button';
import { Area, FormScreen } from '@/ui/FormScreen';
import { haptic, Press } from '@/ui/Press';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { spring } from '@/theme/motion';
import { useTheme } from '@/theme/theme';
import { palette } from '@/theme/tokens';

const STAR = 'M12 2.5l2.94 5.96 6.56.95-4.75 4.63 1.12 6.54L12 17.5l-5.87 3.08 1.12-6.54L2.5 9.41l6.56-.95L12 2.5z';
const LABELS = ['', 'Poor', 'Not great', 'OK', 'Good', 'Excellent'];

/** Write a review: tap the stars (each pops as it fills), then a title and your experience. */
export default function WriteReviewScreen() {
  const { id, title } = useLocalSearchParams<{ id: string; title?: string }>();
  const qc = useQueryClient();
  const [rating, setRating] = useState(5);
  const [head, setHead] = useState('');
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string>();

  const send = async () => {
    setBusy(true);
    try {
      await post(`/products/${id}/reviews`, { rating, title: head || undefined, body });
      void qc.invalidateQueries({ queryKey: ['reviews', Number(id)] });
      void qc.invalidateQueries({ queryKey: ['product'] });
      haptic('success');
      toast('Thanks — your review is published.');
      router.back();
    } catch (e) {
      setError(e instanceof ApiError ? e.field('body') ?? e.message : 'Couldn\'t post your review.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <FormScreen title="Write a review" intro={title} footer={<Button title="Post review" size="lg" block loading={busy} disabled={body.trim().length < 10} onPress={() => void send()} />}>
      <View style={{ alignItems: 'center', gap: 6, paddingVertical: 8 }}>
        <View style={{ flexDirection: 'row', gap: 8 }}>
          {[1, 2, 3, 4, 5].map((n) => (
            <Star key={n} on={n <= rating} onPress={() => setRating(n)} delay={n} />
          ))}
        </View>
        <Text variant="bodyStrong">{LABELS[rating]}</Text>
      </View>
      <Area value={head} onChangeText={setHead} placeholder="Title (optional)" multiline={false} maxLength={120} />
      <Area value={body} onChangeText={setBody} placeholder="How was the fit, quality and install? (10 characters or more)" error={error} maxLength={2000} />
    </FormScreen>
  );
}

function Star({ on, onPress }: { on: boolean; onPress: () => void; delay: number }) {
  const { c } = useTheme();
  const s = useSharedValue(1);
  const style = useAnimatedStyle(() => ({ transform: [{ scale: s.value }] }));

  return (
    <Press
      haptic="selection"
      onPress={() => {
        s.value = withSequence(withSpring(1.35, spring.pop), withSpring(1, spring.pop));
        onPress();
      }}
      accessibilityLabel={`${on ? 'Selected' : ''} star`}>
      <Animated.View style={style}>
        <Svg width={40} height={40} viewBox="0 0 24 24">
          <Path d={STAR} fill={on ? palette.red : c.surface2} />
        </Svg>
      </Animated.View>
    </Press>
  );
}
