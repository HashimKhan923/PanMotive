import { useEffect, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated, { SlideInDown, SlideOutUp } from 'react-native-reanimated';

import { Text } from '@/ui/Text';

function parts(endsAt: string) {
  const left = Math.max(0, Math.floor((new Date(endsAt).getTime() - Date.now()) / 1000));
  return { d: Math.floor(left / 86400), h: Math.floor((left % 86400) / 3600), m: Math.floor((left % 3600) / 60), s: left % 60, left };
}

/** A ticking deal countdown; each digit rolls up as it changes. */
export function Countdown({ endsAt, color = '#fff', box = 'rgba(0,0,0,0.22)' }: { endsAt: string; color?: string; box?: string }) {
  const [t, setT] = useState(() => parts(endsAt));

  useEffect(() => {
    const id = setInterval(() => setT(parts(endsAt)), 1000);
    return () => clearInterval(id);
  }, [endsAt]);

  if (t.left <= 0) {
    return (
      <Text variant="smallStrong" style={{ color }}>
        Ended
      </Text>
    );
  }

  const pad = (n: number) => String(n).padStart(2, '0');

  return (
    <View style={styles.row} accessibilityLabel={`Ends in ${t.d} days ${t.h} hours ${t.m} minutes`}>
      {t.d > 0 && (
        <Text variant="smallStrong" style={{ color, marginRight: 4 }}>
          {t.d}d
        </Text>
      )}
      {[pad(t.h), pad(t.m), pad(t.s)].map((v, i) => (
        <View key={i} style={styles.row}>
          {i > 0 && (
            <Text variant="smallStrong" style={{ color, marginHorizontal: 2 }}>
              :
            </Text>
          )}
          <View style={[styles.box, { backgroundColor: box }]}>
            {v.split('').map((ch, j) => (
              <View key={j} style={styles.digit}>
                <Animated.Text key={ch + i + j + v} entering={SlideInDown.duration(220)} exiting={SlideOutUp.duration(220)} style={[styles.num, { color }]}>
                  {ch}
                </Animated.Text>
              </View>
            ))}
          </View>
        </View>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center' },
  box: { flexDirection: 'row', borderRadius: 5, paddingHorizontal: 4, paddingVertical: 1 },
  digit: { width: 9, height: 18, overflow: 'hidden', alignItems: 'center' },
  num: { fontFamily: 'Inter_700Bold', fontSize: 13, lineHeight: 18, fontVariant: ['tabular-nums'] },
});
