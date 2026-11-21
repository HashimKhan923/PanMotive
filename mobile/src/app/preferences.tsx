import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { StyleSheet, View } from 'react-native';
import Animated, { FadeInDown, LinearTransition } from 'react-native-reanimated';

import { ApiError, get, patch } from '@/api/client';
import { usePush } from '@/notify/push';
import { FormScreen } from '@/ui/FormScreen';
import { haptic, Press } from '@/ui/Press';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { useTheme } from '@/theme/theme';
import { palette, radius } from '@/theme/tokens';

type Prefs = { emails: { key: string; label: string; hint: string; on: boolean }[]; store_marketing: boolean; sms: boolean; phone: string | null };

/** Push notifications on this phone, which emails you get, store marketing and texts. */
export default function PreferencesScreen() {
  const { c } = useTheme();
  const qc = useQueryClient();
  const push = usePush();
  const q = useQuery({ queryKey: ['preferences'], queryFn: () => get<Prefs>('/preferences') });
  const save = useMutation({
    mutationFn: (body: object) => patch<Prefs>('/preferences', body),
    onSuccess: (p) => qc.setQueryData(['preferences'], p),
    onError: (e) => toast(e instanceof ApiError ? e.message : 'Couldn\'t save that.', 'error'),
  });
  const p = q.data;

  return (
    <FormScreen title="Notifications & emails">
      <Group title="This phone">
        <Switch label="Push notifications" hint="Shipped, delivered, price drops and replies." on={push.enabled} onToggle={() => void push.toggle()} />
      </Group>
      {p ? (
        <>
          <Group title="Emails">
            {p.emails.map((e) => (
              <Switch key={e.key} label={e.label} hint={e.hint} on={e.on} onToggle={() => save.mutate({ email: e.key, on: !e.on })} />
            ))}
          </Group>
          <Group title="Marketing">
            <Switch label="Emails from stores" hint="Offers and news from stores you've bought from." on={p.store_marketing} onToggle={() => save.mutate({ store_marketing: !p.store_marketing })} />
            <Switch label="Text messages" hint={p.phone ? `To ${p.phone}. Reply STOP to any text to opt out.` : 'Add a phone number in your profile first.'} on={p.sms} onToggle={() => save.mutate({ sms: !p.sms })} />
          </Group>
        </>
      ) : null}
      <View style={{ height: 1, backgroundColor: c.line, opacity: 0 }} />
    </FormScreen>
  );
}

function Group({ title, children }: { title: string; children: React.ReactNode }) {
  const { c } = useTheme();
  return (
    <Animated.View entering={FadeInDown.springify()} layout={LinearTransition}>
      <Text variant="tiny" tone="muted" style={{ marginBottom: 8, marginLeft: 4 }}>
        {title}
      </Text>
      <View style={[styles.group, { backgroundColor: c.surface, borderColor: c.line }]}>{children}</View>
    </Animated.View>
  );
}

export function Switch({ label, hint, on, onToggle }: { label: string; hint?: string; on: boolean; onToggle: () => void }) {
  const { c } = useTheme();
  return (
    <Press
      onPress={() => {
        haptic('selection');
        onToggle();
      }}
      haptic={false}
      scaleTo={0.99}
      accessibilityRole="switch"
      accessibilityState={{ checked: on }}
      style={[styles.row, { borderTopColor: c.line }]}>
      <View style={{ flex: 1 }}>
        <Text variant="bodyStrong">{label}</Text>
        {hint ? (
          <Text variant="small" tone="muted">
            {hint}
          </Text>
        ) : null}
      </View>
      <View style={[styles.track, { backgroundColor: on ? palette.red : c.surface2 }]}>
        <Animated.View layout={LinearTransition.springify().damping(16)} style={[styles.knob, { alignSelf: on ? 'flex-end' : 'flex-start' }]} />
      </View>
    </Press>
  );
}

const styles = StyleSheet.create({
  group: { borderWidth: 1, borderRadius: radius.lg, overflow: 'hidden' },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 14, borderTopWidth: StyleSheet.hairlineWidth, marginTop: -StyleSheet.hairlineWidth },
  track: { width: 50, height: 30, borderRadius: 15, padding: 3, justifyContent: 'center' },
  knob: { width: 24, height: 24, borderRadius: 12, backgroundColor: '#fff' },
});
