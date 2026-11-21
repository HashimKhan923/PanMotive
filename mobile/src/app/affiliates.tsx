import { useQuery } from '@tanstack/react-query';
import * as Clipboard from 'expo-clipboard';
import { StyleSheet, View } from 'react-native';

import { get } from '@/api/client';
import { Button } from '@/ui/Button';
import { FormScreen } from '@/ui/FormScreen';
import { haptic } from '@/ui/Press';
import { Empty } from '@/ui/Section';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { useTheme } from '@/theme/theme';
import { radius } from '@/theme/tokens';

type Aff = { store: string | null; status: string; commission_pct: number; link: string | null; conversions: number };

/** Affiliate links for stores you promote: copy your link, see sales it brought in. */
export default function AffiliatesScreen() {
  const { c } = useTheme();
  const q = useQuery({ queryKey: ['affiliates'], queryFn: () => get<{ affiliates: Aff[] }>('/affiliates').then((r) => r.affiliates) });

  return (
    <FormScreen title="Affiliate links" intro="Some stores pay a commission when someone buys through your link. Join from a store's page.">
      {q.data && !q.data.length ? <Empty icon="users" title="No affiliate programs yet" body="Look for an affiliate program on a store you like." /> : null}
      {q.data?.map((a, i) => (
        <View key={i} style={[styles.card, { backgroundColor: c.surface, borderColor: c.line }]}>
          <Text variant="bodyStrong">{a.store}</Text>
          <Text variant="small" tone="muted">
            {a.commission_pct}% commission · {a.conversions} {a.conversions === 1 ? 'sale' : 'sales'} · <Text variant="small" style={{ textTransform: 'capitalize' }}>{a.status}</Text>
          </Text>
          {a.link ? (
            <Button
              title="Copy my link"
              icon="copy"
              kind="outline"
              size="sm"
              style={{ alignSelf: 'flex-start', marginTop: 8 }}
              onPress={async () => {
                await Clipboard.setStringAsync(a.link!);
                haptic('success');
                toast('Link copied');
              }}
            />
          ) : null}
        </View>
      ))}
    </FormScreen>
  );
}

const styles = StyleSheet.create({ card: { borderWidth: 1, borderRadius: radius.lg, padding: 14, gap: 2 } });
