import { router } from 'expo-router';
import { StyleSheet, View } from 'react-native';
import Animated, { FadeInDown, FadeInRight } from 'react-native-reanimated';

import { Button } from '@/ui/Button';
import { FormScreen } from '@/ui/FormScreen';
import { Icon } from '@/ui/Icon';
import { Text } from '@/ui/Text';
import { useTheme } from '@/theme/theme';
import { palette, radius } from '@/theme/tokens';

const STEPS: [string, string, string][] = [
  ['vehicle', '1. Add your vehicle', 'Choose your year, make and model. We keep it in your garage.'],
  ['check', '2. Look for "Fits your vehicle"', 'The green check means the seller listed the part for your exact vehicle.'],
  ['shield', '3. You\'re covered', 'If it doesn\'t fit, request a return and pick "Doesn\'t fit — the listing said it fits my vehicle".'],
];
const GET = [
  'A full refund of what you paid for the part.',
  'Free return shipping. The seller pays for it, not you.',
  'The seller can\'t simply say no. If they disagree, Panmotive looks at it and decides.',
  'It works for the whole return window shown on the listing (at least 30 days after delivery).',
];
const APPLIES = [
  'The part showed "Fits your vehicle" for the vehicle in your garage when you ordered it.',
  'The part was not installed in a way that damaged it, and you send it back with its parts.',
  'You start the return within the return window.',
];
const NOT = [
  'Parts that said "Fit not confirmed" for your vehicle. You can still return them under the seller\'s normal return policy.',
  'If you ordered for a different vehicle than the one in your garage. Use "I ordered the wrong part" instead.',
];

/** The Panmotive Fit Guarantee — the same wording as panmotive.com/fit-guarantee. */
export default function FitGuaranteeScreen() {
  const { c } = useTheme();

  return (
    <FormScreen title="Fit Guarantee" footer={<Button title="Add your vehicle" icon="vehicle" size="lg" block onPress={() => router.push('/garage')} />}>
      <Animated.View entering={FadeInDown.springify()} style={styles.hero}>
        <View style={styles.badge}>
          <Icon name="shield" size={28} color="#fff" />
        </View>
        <Text variant="h1" style={{ marginTop: 14 }}>
          Panmotive Fit Guarantee
        </Text>
        <Text variant="body" tone="muted" style={{ marginTop: 6 }}>
          Buy the part listed for your vehicle. If it doesn't fit, you get a full refund, and the return shipping is free for you.
        </Text>
      </Animated.View>
      {STEPS.map(([icon, title, body], i) => (
        <Animated.View key={title} entering={FadeInRight.delay(100 + i * 90).springify()} style={[styles.step, { backgroundColor: c.surface, borderColor: c.line }]}>
          <Icon name={icon} size={22} />
          <View style={{ flex: 1 }}>
            <Text variant="bodyStrong">{title}</Text>
            <Text variant="small" tone="muted">
              {body}
            </Text>
          </View>
        </Animated.View>
      ))}
      <List title="What you get" lines={GET} icon="check" />
      <List title="When it applies" lines={APPLIES} icon="info" />
      <List title="When it doesn't" lines={NOT} icon="x" />
    </FormScreen>
  );
}

function List({ title, lines, icon }: { title: string; lines: string[]; icon: string }) {
  const { c } = useTheme();
  return (
    <View style={{ marginTop: 10, gap: 8 }}>
      <Text variant="h3">{title}</Text>
      {lines.map((l) => (
        <View key={l} style={{ flexDirection: 'row', gap: 10 }}>
          <Icon name={icon} size={16} color={icon === 'check' ? c.success : c.muted} />
          <Text variant="small" style={{ flex: 1 }}>
            {l}
          </Text>
        </View>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  hero: { paddingVertical: 8 },
  badge: { width: 56, height: 56, borderRadius: 16, backgroundColor: palette.red, alignItems: 'center', justifyContent: 'center' },
  step: { flexDirection: 'row', gap: 12, borderWidth: 1, borderRadius: radius.lg, padding: 14, alignItems: 'center' },
});
