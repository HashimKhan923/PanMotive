import { useQuery } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { ApiError, get, post } from '@/api/client';
import type { ProductDetail } from '@/api/types';
import { useSession } from '@/state/session';
import { Button } from '@/ui/Button';
import { FormScreen } from '@/ui/FormScreen';
import { Press } from '@/ui/Press';
import { money } from '@/ui/Price';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { useTheme } from '@/theme/theme';
import { palette, radius } from '@/theme/tokens';

/** Subscribe & Save: how many and how often. First delivery after one interval; skip or cancel anytime. */
export default function SubscribeScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { c } = useTheme();
  const signedIn = !!useSession((s) => s.token);
  const p = useQuery({ queryKey: ['subscribe-product', id], queryFn: () => get<{ products: { slug: string }[] }>('/products/by-ids', { ids: id }).then((r) => r.products[0]) });
  const detail = useQuery({
    queryKey: ['product-sub', p.data?.slug],
    queryFn: () => get<{ product: ProductDetail }>(`/products/${p.data!.slug}`).then((r) => r.product),
    enabled: !!p.data,
  });
  const [qty, setQty] = useState(1);
  const [days, setDays] = useState(30);
  const [busy, setBusy] = useState(false);
  const d = detail.data;
  const pct = d?.subscription?.pct ?? 0;
  const each = d ? d.effective_price * (1 - pct / 100) : 0;

  if (!signedIn) {
    return (
      <FormScreen title="Subscribe & Save" intro="Sign in to set up recurring delivery.">
        <Button title="Sign in" onPress={() => router.replace('/sign-in')} />
      </FormScreen>
    );
  }

  const go = async () => {
    setBusy(true);
    try {
      const r = await post<{ message: string }>('/subscriptions', { product_id: Number(id), quantity: qty, interval_days: days });
      toast(r.message);
      router.replace('/subscriptions');
    } catch (e) {
      toast(e instanceof ApiError ? e.message : 'Couldn\'t start the subscription.', 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <FormScreen
      title="Subscribe & Save"
      intro={d ? `${d.title} — save ${pct}% on every delivery.` : undefined}
      footer={<Button title={d ? `Subscribe · ${money(each * qty)} per delivery` : 'Subscribe'} size="lg" block loading={busy} onPress={() => void go()} />}>
      <Text variant="smallStrong">How many</Text>
      <View style={{ flexDirection: 'row', gap: 8 }}>
        {[1, 2, 3, 4].map((n) => (
          <Press key={n} onPress={() => setQty(n)} haptic="selection" style={{ flex: 1, height: 48, borderRadius: radius.md, borderWidth: 1.5, borderColor: qty === n ? palette.red : c.line, alignItems: 'center', justifyContent: 'center' }}>
            <Text variant="bodyStrong">{n}</Text>
          </Press>
        ))}
      </View>
      <Text variant="smallStrong" style={{ marginTop: 8 }}>
        Deliver
      </Text>
      {(d?.subscription?.intervals ?? []).map((i) => (
        <Press key={i.days} onPress={() => setDays(i.days)} haptic="selection" style={{ borderRadius: radius.md, borderWidth: 1.5, borderColor: days === i.days ? palette.red : c.line, padding: 14, backgroundColor: c.surface }}>
          <Text variant="bodyStrong">{i.label}</Text>
        </Press>
      ))}
      <Text variant="small" tone="muted">
        Locks in {pct}% off every delivery. Skip or cancel anytime from Subscribe & Save.
      </Text>
    </FormScreen>
  );
}
