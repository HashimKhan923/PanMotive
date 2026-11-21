import AsyncStorage from '@react-native-async-storage/async-storage';
import { useMutation } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, TextInput, View, type TextInputProps } from 'react-native';
import Animated, { FadeIn, FadeInDown, LinearTransition } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError, post } from '@/api/client';
import type { Cart } from '@/api/types';
import { forwardCut } from '@/motion/ForwardCut';
import { pay, type Payment } from '@/pay/pay';
import { useCart } from '@/state/cart';
import { useSession } from '@/state/session';
import { Button } from '@/ui/Button';
import { Icon } from '@/ui/Icon';
import { haptic, Press } from '@/ui/Press';
import { money } from '@/ui/Price';
import { GUTTER } from '@/ui/Section';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { useTheme } from '@/theme/theme';
import { font, palette, radius } from '@/theme/tokens';

type Address = { name: string; line1: string; line2: string; city: string; state: string; postal_code: string; phone: string; email: string };
const ADDRESS_KEY = 'pm-checkout-address';
const EMPTY: Address = { name: '', line1: '', line2: '', city: '', state: '', postal_code: '', phone: '', email: '' };

type Placed = { order: { number: string; total: number; status: string; access: string | null }; payment: Payment | null };

/**
 * Checkout: where it's going, discounts and credit, then pay with Apple Pay / Google Pay / card.
 * Uses the same rules as the website's checkout (shipping restrictions, tax, coupons, credit cap).
 */
export default function CheckoutScreen() {
  const { c } = useTheme();
  const insets = useSafeAreaInsets();
  const user = useSession((s) => s.user);
  const remember = useSession((s) => s.rememberGuestOrder);
  const [a, setA] = useState<Address>(EMPTY);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [coupon, setCoupon] = useState('');
  const params = useLocalSearchParams<{ coupon?: string }>();
  // An accepted offer arrives with its code already applied.
  const [applied, setApplied] = useState<string | null>(params.coupon ?? null);
  const [useCredits, setUseCredits] = useState(false);
  const [paying, setPaying] = useState(false);

  // The cart re-prices for the state (shipping restrictions) and the coupon as they change.
  const cart = useCart({ coupon: applied, state: a.state.length === 2 ? a.state : null });
  const d = cart.data;

  useEffect(() => {
    void AsyncStorage.getItem(ADDRESS_KEY).then((raw) => {
      const saved = raw ? (JSON.parse(raw) as Address) : EMPTY;
      setA({ ...saved, name: saved.name || user?.name || '', phone: saved.phone || user?.phone || '', email: saved.email || user?.email || '' });
    });
  }, [user]);

  const field = (k: keyof Address) => ({
    value: a[k],
    onChangeText: (v: string) => {
      setA((old) => ({ ...old, [k]: k === 'state' ? v.toUpperCase().slice(0, 2) : v }));
      if (errors[k]) setErrors((e) => ({ ...e, [k]: '' }));
    },
    error: errors[k],
  });

  const checkCoupon = useMutation({
    mutationFn: (code: string) => post<{ valid: boolean; code: string; discount: number; message: string }>('/cart/coupon', { code }),
    onSuccess: (r) => {
      setApplied(r.code);
      toast(`${r.code}: −${money(r.discount)}`);
    },
    onError: (e) => toast(e instanceof ApiError ? e.message : 'That code didn\'t work.', 'error'),
  });

  const place = async () => {
    setPaying(true);
    setErrors({});
    try {
      await AsyncStorage.setItem(ADDRESS_KEY, JSON.stringify({ ...a, email: user ? '' : a.email }));
      const res = await post<Placed>('/checkout', {
        name: a.name, line1: a.line1, line2: a.line2 || undefined, city: a.city, state: a.state, postal_code: a.postal_code,
        country: 'US', phone: a.phone, email: user ? undefined : a.email, coupon: applied ?? undefined, use_credits: useCredits,
      });
      if (res.order.access) remember(res.order.number, res.order.access);

      if (res.payment) {
        const r = await pay(res.payment, a.name, res.order.number);
        if (r === 'cancelled') {
          toast('Payment not finished — you can pay from your order any time.', 'info');
          router.replace({ pathname: '/order/[number]', params: { number: res.order.number } });
          return;
        }
        if (typeof r === 'object') {
          toast(r.error, 'error');
          router.replace({ pathname: '/order/[number]', params: { number: res.order.number } });
          return;
        }
      }
      haptic('success');
      forwardCut(() => router.replace({ pathname: '/confirmation/[number]', params: { number: res.order.number } }));
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
        toast(e.message, 'error');
      } else toast('Something went wrong. Please try again.', 'error');
    } finally {
      setPaying(false);
    }
  };

  const discount = (d?.coupon_discount ?? 0) + (d?.bundle_savings ?? 0);
  const credits = useCredits ? Math.min(d?.credits_available ?? 0, d?.estimated_total ?? 0) : 0;
  const total = Math.max(0, (d?.estimated_total ?? 0) - credits);

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: c.bg }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <View style={[styles.top, { paddingTop: insets.top + 4 }]}>
        <Press onPress={() => router.back()} style={styles.iconBtn} accessibilityLabel="Back">
          <Icon name="arrow-left" size={22} />
        </Press>
        <Text variant="h3">Checkout</Text>
        <View style={styles.iconBtn}>
          <Icon name="shield" size={20} color={c.muted} />
        </View>
      </View>

      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: GUTTER, paddingBottom: 160 + insets.bottom }}>
        {!user ? (
          <Animated.View entering={FadeIn} style={[styles.card, { borderColor: c.line, backgroundColor: c.surface, flexDirection: 'row', alignItems: 'center', gap: 10 }]}>
            <Icon name="user" size={20} />
            <Text variant="small" style={{ flex: 1 }}>
              Checking out as a guest. Sign in to use your wallet and cashback.
            </Text>
            <Button title="Sign in" size="sm" kind="outline" onPress={() => router.push('/sign-in')} />
          </Animated.View>
        ) : null}

        <Section title="Delivery" icon="location" delay={0}>
          <Field label="Full name" textContentType="name" autoComplete="name" {...field('name')} />
          <Field label="Street address" textContentType="streetAddressLine1" autoComplete="street-address" {...field('line1')} />
          <Field label="Apt, suite (optional)" textContentType="streetAddressLine2" {...field('line2')} />
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <View style={{ flex: 1.4 }}>
              <Field label="City" textContentType="addressCity" {...field('city')} />
            </View>
            <View style={{ flex: 0.7 }}>
              <Field label="State" placeholder="TX" autoCapitalize="characters" maxLength={2} textContentType="addressState" {...field('state')} />
            </View>
            <View style={{ flex: 1 }}>
              <Field label="ZIP" keyboardType="number-pad" textContentType="postalCode" autoComplete="postal-code" maxLength={10} {...field('postal_code')} />
            </View>
          </View>
          <Field label="Phone (for the carrier)" keyboardType="phone-pad" textContentType="telephoneNumber" autoComplete="tel" {...field('phone')} />
          {!user ? <Field label="Email (for your receipt and tracking)" keyboardType="email-address" autoCapitalize="none" textContentType="emailAddress" autoComplete="email" {...field('email')} /> : null}
          {d?.blocked.length ? (
            <Text variant="small" tone="error">
              These can't be shipped to {a.state}: {d.blocked.join(', ')}. Remove them or use another address.
            </Text>
          ) : null}
        </Section>

        <Section title="Discounts" icon="tag" delay={80}>
          {applied ? (
            <View style={[styles.applied, { backgroundColor: c.successBg }]}>
              <Icon name="check" size={18} color={c.success} />
              <Text variant="smallStrong" style={{ flex: 1, color: c.success }}>
                {applied} applied {d?.coupon_discount ? `(−${money(d.coupon_discount)})` : ''}
              </Text>
              <Press onPress={() => setApplied(null)} accessibilityLabel="Remove coupon">
                <Icon name="x" size={18} color={c.success} />
              </Press>
            </View>
          ) : (
            <View style={{ flexDirection: 'row', gap: 10 }}>
              <TextInput
                value={coupon}
                onChangeText={(t) => setCoupon(t.toUpperCase())}
                placeholder="Coupon code"
                placeholderTextColor={c.muted}
                autoCapitalize="characters"
                style={[styles.input, { flex: 1, color: c.fg, borderColor: c.line, backgroundColor: c.surface }]}
              />
              <Button title="Apply" kind="outline" onPress={() => coupon.trim() && checkCoupon.mutate(coupon.trim())} loading={checkCoupon.isPending} />
            </View>
          )}
          {user && (d?.credits_available ?? 0) > 0 ? (
            <Press onPress={() => setUseCredits(!useCredits)} haptic="selection" style={[styles.creditRow, { borderColor: useCredits ? palette.red : c.line }]}>
              <View style={[styles.check, { backgroundColor: useCredits ? palette.red : 'transparent', borderColor: useCredits ? palette.red : c.line }]}>
                {useCredits ? <Icon name="check" size={14} color="#fff" strokeWidth={3} /> : null}
              </View>
              <View style={{ flex: 1 }}>
                <Text variant="smallStrong">Use {money(d!.credits_available)} wallet credit</Text>
                <Text variant="small" tone="muted">
                  Credit can pay up to half of the parts.
                </Text>
              </View>
            </Press>
          ) : null}
        </Section>

        <Section title="Summary" icon="list" delay={160}>
          <Row label={`Items (${d?.count ?? 0})`} value={money(d?.subtotal ?? 0)} />
          <Row label="Shipping" value={d?.shipping ? money(d.shipping) : 'Free'} />
          {discount > 0 ? <Row label="Discounts" value={`−${money(discount)}`} good /> : null}
          {credits > 0 ? <Row label="Wallet credit" value={`−${money(credits)}`} good /> : null}
          {d?.tax_note ? <Row label="Sales tax" value="Added at payment" muted /> : null}
          <View style={[styles.divider, { backgroundColor: c.line }]} />
          <Row label="Total" value={money(total)} strong />
          {d?.fit.vehicle && d.fit.covered > 0 ? (
            <View style={[styles.applied, { backgroundColor: c.successBg, marginTop: 10 }]}>
              <Icon name="shield" size={18} color={c.success} />
              <Text variant="small" style={{ flex: 1, color: c.success }}>
                {d.fit.covered} {d.fit.covered === 1 ? 'item is' : 'items are'} covered by the Fit Guarantee for your {d.fit.vehicle}.
              </Text>
            </View>
          ) : null}
        </Section>
      </ScrollView>

      <View style={[styles.payBar, { paddingBottom: insets.bottom + 12, backgroundColor: c.bg, borderTopColor: c.line }]}>
        <Button
          title={paying ? 'Placing your order…' : `Pay ${money(total)}`}
          icon="shield"
          size="lg"
          block
          loading={paying}
          disabled={!d || d.count === 0 || (d.blocked.length > 0)}
          onPress={() => void place()}
        />
        <Text variant="small" tone="muted" center style={{ marginTop: 8, fontSize: 11 }}>
          Payment is held until delivery. Apple Pay, Google Pay and cards by Stripe.
        </Text>
      </View>
    </KeyboardAvoidingView>
  );
}

function Section({ title, icon, delay, children }: { title: string; icon: string; delay: number; children: React.ReactNode }) {
  const { c } = useTheme();
  return (
    <Animated.View entering={FadeInDown.delay(delay).springify().damping(18)} layout={LinearTransition.springify()} style={[styles.card, { borderColor: c.line, backgroundColor: c.surface }]}>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 12 }}>
        <Icon name={icon} size={18} />
        <Text variant="h3">{title}</Text>
      </View>
      <View style={{ gap: 10 }}>{children}</View>
    </Animated.View>
  );
}

function Field({ label, error, ...rest }: TextInputProps & { label: string; error?: string }) {
  const { c } = useTheme();
  const [focus, setFocus] = useState(false);
  return (
    <View>
      <Text variant="small" tone="muted" style={{ marginBottom: 4, fontSize: 12 }}>
        {label}
      </Text>
      <TextInput
        {...rest}
        onFocus={() => setFocus(true)}
        onBlur={() => setFocus(false)}
        placeholderTextColor={c.muted}
        style={[styles.input, { color: c.fg, backgroundColor: c.bg, borderColor: error ? palette.red : focus ? c.fg : c.line }]}
      />
      {error ? (
        <Animated.View entering={FadeIn}>
          <Text variant="small" tone="error" style={{ marginTop: 4, fontSize: 12 }}>
            {error}
          </Text>
        </Animated.View>
      ) : null}
    </View>
  );
}

function Row({ label, value, strong, good, muted }: { label: string; value: string; strong?: boolean; good?: boolean; muted?: boolean }) {
  return (
    <View style={{ flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 2 }}>
      <Text variant={strong ? 'bodyStrong' : 'small'} tone={muted ? 'muted' : 'fg'}>
        {label}
      </Text>
      <Text variant={strong ? 'price' : 'smallStrong'} tone={good ? 'success' : muted ? 'muted' : 'fg'}>
        {value}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 8, paddingBottom: 4 },
  iconBtn: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  card: { borderWidth: 1, borderRadius: radius.lg, padding: 16, marginBottom: 14 },
  input: { height: 48, borderWidth: 1, borderRadius: radius.md, paddingHorizontal: 14, fontFamily: font.regular, fontSize: 15 },
  applied: { flexDirection: 'row', alignItems: 'center', gap: 8, borderRadius: radius.md, padding: 12 },
  creditRow: { flexDirection: 'row', alignItems: 'center', gap: 12, borderWidth: 1.5, borderRadius: radius.md, padding: 12 },
  check: { width: 22, height: 22, borderRadius: 6, borderWidth: 1.5, alignItems: 'center', justifyContent: 'center' },
  divider: { height: StyleSheet.hairlineWidth, marginVertical: 8 },
  payBar: { borderTopWidth: StyleSheet.hairlineWidth, paddingHorizontal: GUTTER, paddingTop: 12 },
});
