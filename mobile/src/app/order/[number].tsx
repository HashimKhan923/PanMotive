import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router, useLocalSearchParams } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useEffect, useState } from 'react';
import { ScrollView, StyleSheet, TextInput, View } from 'react-native';
import Animated, { FadeIn, FadeInDown, FadeInLeft, useAnimatedStyle, useSharedValue, withDelay, withTiming } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError, api, get, post } from '@/api/client';
import { pay, type Payment } from '@/pay/pay';
import { useSession } from '@/state/session';
import { Button } from '@/ui/Button';
import { Icon } from '@/ui/Icon';
import { haptic, Press } from '@/ui/Press';
import { money } from '@/ui/Price';
import { GUTTER } from '@/ui/Section';
import { Sheet } from '@/ui/Sheet';
import { Skeleton } from '@/ui/Skeleton';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { timing } from '@/theme/motion';
import { useTheme } from '@/theme/theme';
import { font, palette, radius } from '@/theme/tokens';

type Item = {
  id: number;
  title: string;
  variant: string | null;
  slug: string | null;
  product_id: number;
  image: string | null;
  quantity: number;
  line_total: number;
  status: string;
  status_label: string;
  seller: { name: string; slug: string } | null;
  tracking: { carrier: string; number: string; url: string | null; shipped_at: string | null; delivered_at: string | null } | null;
  timeline: { status: string; label: string; note: string | null; at: string }[];
  fit: { vehicle: string; guaranteed: boolean } | null;
  return: { id: number; rma: string; status: string; reason: string; resolution: string | null; fit_guarantee: boolean; escalated: boolean; can_escalate: boolean; events: { event: string; by: string; note: string | null; at: string }[] } | null;
  claim: { number: string; status: string; reason: string } | null;
  can: { confirm_delivery: boolean; return: boolean; claim: boolean; review: boolean; message_seller: boolean };
  return_deadline: string | null;
  return_reasons: string[];
};
type Order = {
  number: string;
  status: string;
  status_label: string;
  placed_at: string;
  pay_by: string | null;
  totals: { subtotal: number; shipping: number; tax: number; discount: number; credits: number; total: number };
  ship_to: Record<string, string>;
  progress: number;
  items: Item[];
  claim_reasons: { key: string; label: string }[];
};

const date = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '');

export default function OrderScreen() {
  const { number } = useLocalSearchParams<{ number: string }>();
  const { c } = useTheme();
  const insets = useSafeAreaInsets();
  const qc = useQueryClient();
  const access = useSession((s) => s.guestOrders[number]);
  const [sheet, setSheet] = useState<{ kind: 'return' | 'claim' | 'escalate'; item: Item } | null>(null);

  const q = useQuery({
    queryKey: ['order', number],
    queryFn: () => get<{ order: Order }>(`/orders/${number}`, access ? { access } : undefined).then((r) => r.order),
  });
  const o = q.data;
  const refresh = () => void qc.invalidateQueries({ queryKey: ['order', number] });

  const act = useMutation({
    mutationFn: ({ path, body }: { path: string; body?: object }) => post<{ message?: string; conversation_id?: number }>(path, body),
    onSuccess: (r) => {
      haptic('success');
      if (r.conversation_id) router.push({ pathname: '/messages/[id]', params: { id: String(r.conversation_id) } });
      else if (r.message) toast(r.message);
      setSheet(null);
      refresh();
    },
    onError: (e) => toast(e instanceof ApiError ? e.message : 'That didn\'t work. Try again.', 'error'),
  });

  const payNow = async () => {
    try {
      const r = await api<{ payment: Payment }>(`/orders/${number}/pay`, { method: 'POST', body: {}, headers: access ? { 'X-Order-Access': access } : undefined });
      const res = await pay(r.payment, undefined, number);
      if (res === 'paid') {
        toast('Payment received — thanks!');
        router.replace({ pathname: '/confirmation/[number]', params: { number } });
      } else if (typeof res === 'object') toast(res.error, 'error');
    } catch (e) {
      toast(e instanceof ApiError ? e.message : 'Payments aren\'t available right now.', 'error');
    }
  };

  return (
    <View style={{ flex: 1, backgroundColor: c.bg, paddingTop: insets.top }}>
      <View style={styles.top}>
        <Press onPress={() => (router.canGoBack() ? router.back() : router.replace('/orders'))} style={styles.iconBtn} accessibilityLabel="Back">
          <Icon name="arrow-left" size={22} />
        </Press>
        <Text variant="h3">Order {number}</Text>
        <View style={styles.iconBtn} />
      </View>

      {!o ? (
        <View style={{ padding: GUTTER, gap: 12 }}>
          <Skeleton height={80} radius={radius.lg} />
          <Skeleton height={220} radius={radius.lg} />
        </View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: GUTTER, paddingBottom: insets.bottom + 40 }} refreshControl={undefined}>
          <Animated.View entering={FadeInDown.springify()} style={[styles.card, { backgroundColor: c.surface, borderColor: c.line }]}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
              <View>
                <Text variant="small" tone="muted">
                  Placed {new Date(o.placed_at).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}
                </Text>
                <Text variant="h2" style={{ marginTop: 2 }}>
                  {o.status_label}
                </Text>
              </View>
              <Text variant="price">{money(o.totals.total)}</Text>
            </View>
            <Progress step={o.progress} />
          </Animated.View>

          {o.status === 'placed' && o.pay_by ? (
            <Animated.View entering={FadeIn} style={[styles.pay, { borderColor: palette.red }]}>
              <Text variant="bodyStrong">This order hasn't been paid yet.</Text>
              <Text variant="small" tone="muted">
                Pay by {new Date(o.pay_by).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })} or it will be cancelled and the items released.
              </Text>
              <Button title={`Pay ${money(o.totals.total)} now`} style={{ marginTop: 10 }} onPress={() => void payNow()} />
            </Animated.View>
          ) : null}

          {o.items.map((it, i) => (
            <Animated.View key={it.id} entering={FadeInDown.delay(80 + i * 70).springify()} style={[styles.card, { backgroundColor: c.surface, borderColor: c.line }]}>
              <Press haptic={false} scaleTo={0.98} onPress={() => it.slug && router.push({ pathname: '/product/[slug]', params: { slug: it.slug } })} style={{ flexDirection: 'row', gap: 12 }}>
                <Image source={it.image} style={[styles.img, { backgroundColor: c.surface2 }]} contentFit="cover" />
                <View style={{ flex: 1 }}>
                  <Text variant="smallStrong" numberOfLines={2}>
                    {it.title}
                  </Text>
                  <Text variant="small" tone="muted">
                    {[it.variant, `Qty ${it.quantity}`, money(it.line_total)].filter(Boolean).join(' · ')}
                  </Text>
                  <View style={[styles.pill, { backgroundColor: c.surface2 }]}>
                    <Text variant="smallStrong" style={{ fontSize: 11 }}>
                      {it.status_label}
                    </Text>
                  </View>
                </View>
              </Press>

              {it.tracking ? (
                <Press
                  onPress={() => it.tracking?.url && void WebBrowser.openBrowserAsync(it.tracking.url, { toolbarColor: '#15191E', controlsColor: '#E51B2B' })}
                  style={[styles.track, { backgroundColor: c.surface2 }]}>
                  <Icon name="truck" size={18} />
                  <View style={{ flex: 1 }}>
                    <Text variant="smallStrong">{it.tracking.carrier}</Text>
                    <Text variant="small" tone="muted" style={{ fontFamily: font.medium, letterSpacing: 0.5 }}>
                      {it.tracking.number}
                    </Text>
                  </View>
                  {it.tracking.url ? (
                    <Text variant="smallStrong" style={{ textDecorationLine: 'underline' }}>
                      Track
                    </Text>
                  ) : null}
                </Press>
              ) : null}

              {it.timeline.length ? <Timeline steps={it.timeline} /> : null}

              {it.return ? (
                <View style={[styles.box, { borderColor: c.line }]}>
                  <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                    <Text variant="smallStrong">Return {it.return.rma}</Text>
                    <Text variant="smallStrong" tone="muted" style={{ textTransform: 'capitalize' }}>
                      {it.return.status}
                    </Text>
                  </View>
                  <Text variant="small" tone="muted">
                    {it.return.reason}
                    {it.return.resolution ? ` — ${it.return.resolution}` : ''}
                  </Text>
                  {it.return.fit_guarantee ? (
                    <Text variant="small" tone="success">
                      Covered by the Fit Guarantee: full refund, free return shipping.
                    </Text>
                  ) : null}
                  {it.return.escalated ? (
                    <Text variant="smallStrong">Escalated — a Panmotive specialist is reviewing.</Text>
                  ) : it.return.can_escalate ? (
                    <Button title="Not resolving? Escalate to Panmotive" kind="ghost" size="sm" onPress={() => setSheet({ kind: 'escalate', item: it })} />
                  ) : null}
                </View>
              ) : null}

              {it.claim ? (
                <Text variant="small" tone="muted" style={{ marginTop: 8 }}>
                  Claim {it.claim.number}: {it.claim.reason} ({it.claim.status.replace('_', ' ')})
                </Text>
              ) : null}

              <View style={styles.actions}>
                {it.can.confirm_delivery ? (
                  <Button title="Confirm delivery" size="sm" icon="check" loading={act.isPending} onPress={() => act.mutate({ path: `/order-items/${it.id}/confirm-delivery` })} />
                ) : null}
                {it.can.return ? <Button title="Return" size="sm" kind="outline" onPress={() => setSheet({ kind: 'return', item: it })} /> : null}
                {it.can.review ? (
                  <Button title="Review" size="sm" kind="outline" icon="star" onPress={() => router.push({ pathname: '/review/[id]', params: { id: String(it.product_id), title: it.title } })} />
                ) : null}
                {it.can.message_seller ? <Button title="Message seller" size="sm" kind="ghost" icon="message" onPress={() => act.mutate({ path: `/order-items/${it.id}/contact-seller` })} /> : null}
                {it.can.claim ? <Button title="Problem? Open a claim" size="sm" kind="ghost" onPress={() => setSheet({ kind: 'claim', item: it })} /> : null}
              </View>
              {it.can.return && it.return_deadline ? (
                <Text variant="small" tone="muted" style={{ fontSize: 11 }}>
                  Returns open until {date(it.return_deadline)}
                </Text>
              ) : null}
            </Animated.View>
          ))}

          <Animated.View entering={FadeInDown.delay(200).springify()} style={[styles.card, { backgroundColor: c.surface, borderColor: c.line }]}>
            <Text variant="h3" style={{ marginBottom: 8 }}>
              Summary
            </Text>
            {(
              [
                ['Items', o.totals.subtotal],
                ['Shipping', o.totals.shipping],
                ['Tax', o.totals.tax],
                ['Discounts', -o.totals.discount],
                ['Wallet credit', -o.totals.credits],
              ] as [string, number][]
            )
              .filter(([, v], i) => i < 2 || v !== 0)
              .map(([k, v]) => (
                <View key={k} style={styles.row}>
                  <Text variant="small" tone="muted">
                    {k}
                  </Text>
                  <Text variant="smallStrong">{v < 0 ? `−${money(-v)}` : money(v)}</Text>
                </View>
              ))}
            <View style={[styles.row, { marginTop: 6 }]}>
              <Text variant="bodyStrong">Total</Text>
              <Text variant="price">{money(o.totals.total)}</Text>
            </View>
            <Text variant="small" tone="muted" style={{ marginTop: 12 }}>
              Shipping to {[o.ship_to.name, o.ship_to.line1, o.ship_to.city, o.ship_to.state, o.ship_to.postal_code].filter(Boolean).join(', ')}
            </Text>
          </Animated.View>
        </ScrollView>
      )}

      <ActionSheet sheet={sheet} order={o} onClose={() => setSheet(null)} busy={act.isPending} submit={(path, body) => act.mutate({ path, body })} />
    </View>
  );
}

/** Placed → Paid → Shipped → Delivered: the red bar runs to where the order is. */
function Progress({ step }: { step: number }) {
  const { c } = useTheme();
  const w = useSharedValue(0);
  useEffect(() => {
    w.value = withDelay(200, withTiming(Math.max(0, step - 1) / 3, timing.slow));
  }, [step, w]);
  const bar = useAnimatedStyle(() => ({ width: `${w.value * 100}%` }));

  return (
    <View style={{ marginTop: 16 }}>
      <View style={[styles.ptrack, { backgroundColor: c.surface2 }]}>
        <Animated.View style={[styles.pfill, bar]} />
      </View>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginTop: 6 }}>
        {['Placed', 'Paid', 'Shipped', 'Delivered'].map((s, i) => (
          <Text key={s} variant="small" style={{ fontSize: 11, color: step > i ? c.fg : c.muted }}>
            {s}
          </Text>
        ))}
      </View>
    </View>
  );
}

/** The item's history, drawn in one step at a time. */
function Timeline({ steps }: { steps: Item['timeline'] }) {
  const { c } = useTheme();
  return (
    <View style={{ marginTop: 14 }}>
      {steps.map((s, i) => (
        <Animated.View key={i} entering={FadeInLeft.delay(150 + i * 90).springify()} style={{ flexDirection: 'row', gap: 12 }}>
          <View style={{ alignItems: 'center' }}>
            <View style={[styles.dot, { backgroundColor: i === steps.length - 1 ? palette.red : c.fg }]} />
            {i < steps.length - 1 ? <View style={[styles.vline, { backgroundColor: c.line }]} /> : null}
          </View>
          <View style={{ flex: 1, paddingBottom: 12 }}>
            <Text variant="smallStrong">{s.label}</Text>
            <Text variant="small" tone="muted" style={{ fontSize: 11 }}>
              {new Date(s.at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}
              {s.note ? ` · ${s.note}` : ''}
            </Text>
          </View>
        </Animated.View>
      ))}
    </View>
  );
}

function ActionSheet({
  sheet,
  order,
  onClose,
  busy,
  submit,
}: {
  sheet: { kind: 'return' | 'claim' | 'escalate'; item: Item } | null;
  order: Order | undefined;
  onClose: () => void;
  busy: boolean;
  submit: (path: string, body: object) => void;
}) {
  const { c } = useTheme();
  const [reason, setReason] = useState('');
  const [detail, setDetail] = useState('');

  useEffect(() => {
    setReason('');
    setDetail('');
  }, [sheet]);

  if (!sheet) return <Sheet open={false} onClose={onClose}>{null}</Sheet>;
  const it = sheet.item;
  const options = sheet.kind === 'return' ? it.return_reasons.map((r) => ({ key: r, label: r })) : sheet.kind === 'claim' ? order?.claim_reasons ?? [] : [];

  const go = () => {
    if (sheet.kind === 'return') submit(`/order-items/${it.id}/return`, { reason, detail });
    else if (sheet.kind === 'claim') submit(`/order-items/${it.id}/claim`, { reason, detail });
    else submit(`/returns/${it.return!.id}/escalate`, { note: detail });
  };

  return (
    <Sheet
      open
      onClose={onClose}
      title={sheet.kind === 'return' ? 'Request a return' : sheet.kind === 'claim' ? 'Buyer-protection claim' : 'Escalate to Panmotive'}
      footer={
        <Button
          title={sheet.kind === 'escalate' ? 'Escalate' : 'Submit'}
          block
          style={{ flex: 1 }}
          loading={busy}
          disabled={sheet.kind !== 'escalate' && (!reason || (sheet.kind === 'claim' && detail.trim().length < 2))}
          onPress={go}
        />
      }>
      <ScrollView contentContainerStyle={{ paddingHorizontal: 16, gap: 8, paddingBottom: 12 }} keyboardShouldPersistTaps="handled">
        {sheet.kind === 'return' && it.fit?.guaranteed ? (
          <View style={[styles.box, { borderColor: 'transparent', backgroundColor: c.successBg }]}>
            <Text variant="small" tone="success">
              Listed as fitting your {it.fit.vehicle}, so the Fit Guarantee covers it: full refund and free return shipping if it doesn't fit.
            </Text>
          </View>
        ) : null}
        {options.map((o) => (
          <Press key={o.key} onPress={() => setReason(o.key)} haptic="selection" style={[styles.option, { borderColor: reason === o.key ? palette.red : c.line, backgroundColor: c.surface }]}>
            <View style={[styles.radio, { borderColor: reason === o.key ? palette.red : c.line }]}>{reason === o.key ? <View style={styles.radioDot} /> : null}</View>
            <Text variant="smallStrong" style={{ flex: 1 }}>
              {o.label}
            </Text>
          </Press>
        ))}
        <TextInput
          value={detail}
          onChangeText={setDetail}
          multiline
          placeholder={sheet.kind === 'claim' ? 'What happened? (required)' : 'Add any details (optional)'}
          placeholderTextColor={c.muted}
          style={[styles.textarea, { color: c.fg, borderColor: c.line, backgroundColor: c.surface }]}
        />
        {sheet.kind === 'claim' ? (
          <Text variant="small" tone="muted">
            The seller has 72 hours to respond before Panmotive steps in.
          </Text>
        ) : null}
      </ScrollView>
    </Sheet>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 8, height: 52 },
  iconBtn: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  card: { borderWidth: 1, borderRadius: radius.lg, padding: 14, marginBottom: 12 },
  pay: { borderWidth: 1.5, borderRadius: radius.lg, padding: 14, marginBottom: 12, backgroundColor: 'rgba(229,27,43,0.08)' },
  img: { width: 64, height: 64, borderRadius: 12 },
  pill: { alignSelf: 'flex-start', borderRadius: 999, paddingHorizontal: 8, paddingVertical: 2, marginTop: 6 },
  track: { flexDirection: 'row', alignItems: 'center', gap: 10, borderRadius: radius.md, padding: 12, marginTop: 12 },
  box: { borderWidth: 1, borderRadius: radius.md, padding: 12, marginTop: 12, gap: 4 },
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 12 },
  row: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 3 },
  ptrack: { height: 6, borderRadius: 3, overflow: 'hidden' },
  pfill: { height: 6, borderRadius: 3, backgroundColor: palette.red },
  dot: { width: 10, height: 10, borderRadius: 5, marginTop: 4 },
  vline: { width: 2, flex: 1, marginTop: 2 },
  option: { flexDirection: 'row', alignItems: 'center', gap: 12, borderWidth: 1.5, borderRadius: radius.md, padding: 14 },
  radio: { width: 20, height: 20, borderRadius: 10, borderWidth: 2, alignItems: 'center', justifyContent: 'center' },
  radioDot: { width: 10, height: 10, borderRadius: 5, backgroundColor: palette.red },
  textarea: { minHeight: 90, borderWidth: 1, borderRadius: radius.md, padding: 12, fontFamily: font.regular, fontSize: 15, textAlignVertical: 'top' },
});
