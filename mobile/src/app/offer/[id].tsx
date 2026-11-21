import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { StyleSheet, TextInput, View } from 'react-native';
import Animated, { FadeIn } from 'react-native-reanimated';

import { ApiError, get, post } from '@/api/client';
import type { ProductDetail } from '@/api/types';
import { useSession } from '@/state/session';
import { Button } from '@/ui/Button';
import { Area, FormScreen } from '@/ui/FormScreen';
import { money } from '@/ui/Price';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { useTheme } from '@/theme/theme';
import { font, palette, radius } from '@/theme/tokens';

type Offer = { id: number; status: string; quantity: number; price: number; counter_price: number | null; coupon: string | null; expires_at: string | null };

/** Make an offer; see the seller's answer; accept or decline a counter; buy at the agreed price. */
export default function OfferScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { c } = useTheme();
  const qc = useQueryClient();
  const signedIn = !!useSession((s) => s.token);

  const product = useQuery({ queryKey: ['product-by-id', id], queryFn: () => get<{ products: ProductDetail[] }>('/products/by-ids', { ids: id }).then((r) => r.products[0]) });
  const mine = useQuery({ queryKey: ['offer', id], queryFn: () => get<{ offer: Offer | null }>(`/products/${id}/offer`).then((r) => r.offer), enabled: signedIn });
  const [price, setPrice] = useState('');
  const [qty, setQty] = useState('1');
  const [note, setNote] = useState('');

  useEffect(() => {
    if (product.data && !price) setPrice((product.data.effective_price * 0.9).toFixed(2));
  }, [product.data, price]);

  const refresh = () => void qc.invalidateQueries({ queryKey: ['offer', id] });
  const make = useMutation({
    mutationFn: () => post<{ message: string }>(`/products/${id}/offer`, { price: Number(price), quantity: Number(qty) || 1, message: note || undefined }),
    onSuccess: (r) => {
      toast(r.message);
      refresh();
    },
    onError: (e) => toast(e instanceof ApiError ? e.message : 'Couldn\'t send the offer.', 'error'),
  });
  const respond = useMutation({
    mutationFn: (action: 'accept' | 'decline') => post<{ message: string }>(`/products/${id}/offer/counter`, { action }),
    onSuccess: (r) => {
      toast(r.message);
      refresh();
    },
    onError: (e) => toast(e instanceof ApiError ? e.message : 'That didn\'t work.', 'error'),
  });
  const buy = useMutation({
    mutationFn: () => post<{ coupon: string }>(`/products/${id}/offer/buy`),
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['cart'] });
      toast(`Added at your price — code ${r.coupon} is applied at checkout`);
      router.replace({ pathname: '/checkout', params: { coupon: r.coupon } });
    },
  });

  if (!signedIn) {
    return (
      <FormScreen title="Make an offer" intro="Sign in to make the seller an offer.">
        <Button title="Sign in" onPress={() => router.replace('/sign-in')} />
      </FormScreen>
    );
  }

  const o = mine.data;
  const p = product.data;

  return (
    <FormScreen
      title="Make an offer"
      intro={p ? `${p.title} — listed at ${money(p.effective_price)}` : undefined}
      footer={
        o?.status === 'accepted' ? (
          <Button title={`Buy now at ${money(o.counter_price ?? o.price)}`} size="lg" block loading={buy.isPending} onPress={() => buy.mutate()} />
        ) : o?.status === 'countered' ? (
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <Button title="Decline" kind="outline" style={{ flex: 1 }} onPress={() => respond.mutate('decline')} />
            <Button title={`Accept ${money(o.counter_price ?? 0)}`} style={{ flex: 1.5 }} loading={respond.isPending} onPress={() => respond.mutate('accept')} />
          </View>
        ) : o?.status === 'pending' ? null : (
          <Button title="Send offer" size="lg" block loading={make.isPending} disabled={!(Number(price) > 0)} onPress={() => make.mutate()} />
        )
      }>
      {o ? (
        <Animated.View entering={FadeIn} style={[styles.status, { backgroundColor: c.surface, borderColor: o.status === 'accepted' ? '#18794E' : palette.red }]}>
          <Text variant="h3">
            {o.status === 'pending' ? 'Offer sent' : o.status === 'countered' ? 'The seller countered' : o.status === 'accepted' ? 'Offer accepted!' : 'Offer ' + o.status}
          </Text>
          <Text variant="body" tone="muted">
            You offered {money(o.price)} × {o.quantity}.
            {o.counter_price ? ` The seller asks ${money(o.counter_price)} each.` : ''}
          </Text>
          {o.status === 'pending' ? (
            <Text variant="small" tone="muted">
              The seller has 48 hours to respond. We'll notify you.
            </Text>
          ) : null}
          {o.expires_at && o.status === 'accepted' ? (
            <Text variant="small" tone="muted">
              Price held until {new Date(o.expires_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}
            </Text>
          ) : null}
        </Animated.View>
      ) : (
        <>
          <Text variant="smallStrong">Your price (each)</Text>
          <View style={[styles.money, { borderColor: c.line, backgroundColor: c.surface }]}>
            <Text variant="h2">$</Text>
            <TextInput value={price} onChangeText={setPrice} keyboardType="decimal-pad" style={{ flex: 1, color: c.fg, fontFamily: font.bold, fontSize: 28 }} />
          </View>
          <Text variant="smallStrong">Quantity</Text>
          <TextInput value={qty} onChangeText={setQty} keyboardType="number-pad" style={[styles.qty, { borderColor: c.line, color: c.fg, backgroundColor: c.surface }]} />
          <Area value={note} onChangeText={setNote} placeholder="Message to the seller (optional)" maxLength={300} />
        </>
      )}
    </FormScreen>
  );
}

const styles = StyleSheet.create({
  status: { borderWidth: 1.5, borderRadius: radius.lg, padding: 16, gap: 6 },
  money: { flexDirection: 'row', alignItems: 'center', gap: 6, borderWidth: 1, borderRadius: radius.md, paddingHorizontal: 16, height: 64 },
  qty: { height: 50, borderWidth: 1, borderRadius: radius.md, paddingHorizontal: 14, fontFamily: font.semibold, fontSize: 16, width: 100 },
});
