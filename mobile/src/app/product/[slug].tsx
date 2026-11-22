import { useQuery } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Share, StyleSheet, View, useWindowDimensions } from 'react-native';
import RenderHtml from 'react-native-render-html';
import Animated, {
  Extrapolation,
  FadeIn,
  FadeInDown,
  interpolate,
  useAnimatedScrollHandler,
  useAnimatedStyle,
  useSharedValue,
  withSpring,
} from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useAddToCart, useToggleWishlist } from '@/api/actions';
import { get } from '@/api/client';
import type { ProductCard, ProductDetail } from '@/api/types';
import { Gallery } from '@/features/product/Gallery';
import { QaPreview, ReviewsPreview } from '@/features/product/Social';
import { usePrefs } from '@/state/prefs';
import { useGarage } from '@/state/session';
import { Button } from '@/ui/Button';
import { Countdown } from '@/ui/Countdown';
import { Icon } from '@/ui/Icon';
import { haptic, Press } from '@/ui/Press';
import { money, Price } from '@/ui/Price';
import { Rating } from '@/ui/Rating';
import { Gap, GUTTER, ProductRail, SectionTitle } from '@/ui/Section';
import { Skeleton } from '@/ui/Skeleton';
import type { HostView } from '@/ui/host';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { spring } from '@/theme/motion';
import { useTheme } from '@/theme/theme';
import { font, palette, radius } from '@/theme/tokens';

export default function ProductScreen() {
  const { slug, img } = useLocalSearchParams<{ slug: string; img?: string }>();
  const { c, dark } = useTheme();
  const { width } = useWindowDimensions();
  const insets = useSafeAreaInsets();
  const vehicle = useGarage((s) => s.vehicle);
  const viewed = usePrefs((s) => s.viewed);
  const toggleCompare = usePrefs((s) => s.toggleCompare);
  const comparing = usePrefs((s) => s.compare);

  const q = useQuery({
    queryKey: ['product', slug, vehicle?.id ?? null],
    queryFn: ({ signal }) => get<{ product: ProductDetail }>(`/products/${slug}`, undefined, signal).then((r) => r.product),
  });
  const p = q.data;
  const related = useQuery({
    queryKey: ['related', p?.id],
    queryFn: () => get<{ frequently_bought: ProductCard[]; also_viewed: ProductCard[]; related: ProductCard[] }>(`/products/${p!.id}/related`),
    enabled: !!p,
  });

  useEffect(() => {
    if (p) viewed(p.id);
  }, [p, viewed]);

  const [variantId, setVariantId] = useState<number | null>(null);
  const [qty, setQty] = useState(1);
  const [photo, setPhoto] = useState(0);
  const variant = p?.variants.find((v) => v.id === variantId) ?? null;

  // Default option: the first one in stock (like the website).
  useEffect(() => {
    if (p && p.variants.length && variantId === null) setVariantId((p.variants.find((v) => v.in_stock) ?? p.variants[0]).id);
  }, [p, variantId]);

  const photos = useMemo(() => {
    if (!p) return img ? [{ id: 'morph', thumb: img, large: img }] : [];
    return p.gallery.length ? p.gallery : [];
  }, [p, img]);

  const pickVariant = (id: number) => {
    haptic('selection');
    setVariantId(id);
    const v = p?.variants.find((x) => x.id === id);
    const at = v?.media_id ? photos.findIndex((ph) => ph.id === v.media_id) : -1;
    if (at >= 0) setPhoto(at);
  };

  const scrollY = useSharedValue(0);
  const onScroll = useAnimatedScrollHandler((e) => {
    scrollY.value = e.contentOffset.y;
  });
  // The top bar fades in once the photo has scrolled away; the title slides up into it.
  const bar = useAnimatedStyle(() => ({ backgroundColor: `rgba(${dark ? '21,25,30' : '255,255,255'},${interpolate(scrollY.value, [width * 0.6, width * 0.85], [0, 0.96], Extrapolation.CLAMP)})` }));
  const barTitle = useAnimatedStyle(() => ({
    opacity: interpolate(scrollY.value, [width * 0.75, width * 0.95], [0, 1], Extrapolation.CLAMP),
    transform: [{ translateY: interpolate(scrollY.value, [width * 0.75, width * 0.95], [10, 0], Extrapolation.CLAMP) }],
  }));

  const price = variant ? variant.price : p?.price ?? 0;
  const sale = variant ? variant.sale_price : p?.sale_price ?? null;
  const stock = variant ? variant.stock : p?.stock ?? 0;
  const soldOut = p ? stock <= 0 : false;
  const paused = !!p?.vacation?.paused;

  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <Animated.ScrollView onScroll={onScroll} scrollEventThrottle={16} contentContainerStyle={{ paddingBottom: 140 + insets.bottom }}>
        <Gallery photos={photos} scrollY={scrollY} index={photo} onIndex={setPhoto} />

        <View style={[styles.sheet, { backgroundColor: c.bg }]}>
          {!p ? (
            <View style={{ gap: 12 }}>
              <Skeleton width="40%" height={12} />
              <Skeleton height={26} />
              <Skeleton width="70%" height={26} />
              <Skeleton width="35%" height={32} style={{ marginTop: 8 }} />
            </View>
          ) : (
            <>
              <Animated.View entering={FadeInDown.springify().damping(18)}>
                {p.fits_vehicle ? (
                  <Text variant="tiny" tone="accent" style={{ color: dark ? c.muted : c.accentInk }}>
                    Fits {p.fits_vehicle}
                  </Text>
                ) : null}
                <Text variant="h2" style={{ marginTop: 6 }}>
                  {p.title}
                </Text>
                <View style={styles.metaRow}>
                  {p.seller && p.seller.rating > 0 ? <Rating value={p.seller.rating} count={p.seller.rating_count} /> : null}
                  <Text variant="small" tone="muted" style={{ textTransform: 'capitalize' }}>
                    {p.condition}
                  </Text>
                  {p.part_number ? (
                    <Text variant="small" tone="muted">
                      Part #{p.part_number}
                    </Text>
                  ) : null}
                  {p.oem_number ? (
                    <Text variant="small" tone="muted">
                      OEM #{p.oem_number}
                    </Text>
                  ) : null}
                </View>
              </Animated.View>

              <Animated.View entering={FadeInDown.delay(60).springify().damping(18)} style={{ marginTop: 16 }}>
                {p.price_range && !variant ? (
                  <Text variant="priceLarge">
                    {money(p.price_range.min)}
                    {p.price_range.max > p.price_range.min ? ` – ${money(p.price_range.max)}` : ''}
                  </Text>
                ) : (
                  <Price price={price} sale={sale} basis={p.basis} size="lg" showOff />
                )}
              </Animated.View>

              {p.deal ? (
                <Animated.View entering={FadeIn.delay(120)} style={styles.deal}>
                  <Text variant="smallStrong" style={{ color: '#fff' }}>
                    {p.deal.name}
                  </Text>
                  <Countdown endsAt={p.deal.ends_at} />
                </Animated.View>
              ) : null}

              <FitCheck product={p} />

              <View style={{ marginTop: 14, gap: 8 }}>
                <View style={styles.inline}>
                  <View style={[styles.stockDot, { backgroundColor: soldOut ? palette.red : '#18794E' }]} />
                  <Text variant="smallStrong" tone={soldOut ? 'error' : 'success'}>
                    {soldOut ? 'Out of stock' : 'In stock'}
                  </Text>
                  {!soldOut && stock <= 5 ? (
                    <Text variant="small" tone="muted">
                      — only {stock} left
                    </Text>
                  ) : null}
                </View>
                <View style={styles.inline}>
                  <Icon name="truck" size={16} color={c.muted} />
                  <Text variant="small" tone="muted" style={{ flex: 1 }}>
                    Ships {p.shipping.handling_days <= 0 ? 'same day' : `within ${p.shipping.handling_days} business ${p.shipping.handling_days === 1 ? 'day' : 'days'}`}
                    {p.shipping.free ? ' · ' : ''}
                    {p.shipping.free ? <Text variant="smallStrong" tone="success">Free shipping</Text> : null}
                  </Text>
                </View>
                <View style={styles.inline}>
                  <Icon name="clock" size={16} color={c.muted} />
                  <Text variant="small">
                    Get it <Text variant="smallStrong">{fmtDate(p.shipping.eta_from)} – {fmtDate(p.shipping.eta_to)}</Text>
                    <Text variant="small" tone="muted"> (estimate)</Text>
                  </Text>
                </View>
                {p.shipping.excluded_states.length ? (
                  <Text variant="small" tone="muted">
                    Doesn't ship to: {p.shipping.excluded_states.join(', ')}
                  </Text>
                ) : null}
                {p.vacation ? (
                  <View style={[styles.note, { backgroundColor: c.warningBg }]}>
                    <Icon name="clock" size={16} color={c.warning} />
                    <Text variant="small" style={{ color: c.warning, flex: 1 }}>
                      {p.vacation.message}
                      {p.vacation.until ? ` (back ${fmtDate(p.vacation.until)})` : ''}
                      {p.vacation.paused ? ' Not taking new orders until then.' : ''}
                    </Text>
                  </View>
                ) : null}
              </View>

              {p.variants.length ? (
                <View style={{ marginTop: 20 }}>
                  <Text variant="smallStrong">Option{variant ? `: ${variant.label}` : ''}</Text>
                  <View style={styles.chips}>
                    {p.variants.map((v) => {
                      const on = v.id === variantId;
                      return (
                        <Press
                          key={v.id}
                          onPress={() => pickVariant(v.id)}
                          haptic={false}
                          scaleTo={0.94}
                          accessibilityState={{ selected: on, disabled: !v.in_stock }}
                          style={[styles.chip, { borderColor: on ? palette.red : c.line, backgroundColor: on ? (dark ? 'rgba(229,27,43,0.14)' : '#FDEBED') : c.surface, opacity: v.in_stock ? 1 : 0.5 }]}>
                          {v.image ? <Image source={v.image} style={styles.chipImg} contentFit="cover" /> : null}
                          <Text variant="smallStrong" style={!v.in_stock ? { textDecorationLine: 'line-through' } : undefined}>
                            {v.label}
                          </Text>
                        </Press>
                      );
                    })}
                  </View>
                </View>
              ) : null}

              {!soldOut ? (
                <View style={[styles.inline, { marginTop: 20, justifyContent: 'space-between' }]}>
                  <Text variant="smallStrong">Quantity</Text>
                  <Stepper value={qty} max={Math.max(1, Math.min(99, stock))} onChange={setQty} />
                </View>
              ) : null}

              {p.volume_discounts.length ? (
                <View style={[styles.box, { borderColor: c.line, backgroundColor: c.surface }]}>
                  <Text variant="smallStrong">Buy more, save more</Text>
                  {p.volume_discounts.map((t) => (
                    <Text key={t.min_quantity} variant="small" tone="muted">
                      {t.min_quantity}+ units: {t.type === 'percentage' ? `${t.value}% off` : `${money(t.value)} off each`}
                    </Text>
                  ))}
                </View>
              ) : null}

              <View style={styles.actions}>
                <SmallAction icon="grid" label={comparing.includes(p.id) ? 'Comparing' : 'Compare'} onPress={() => {
                  const r = toggleCompare(p.id);
                  toast(r === 'full' ? 'You can compare up to 4 parts.' : r === 'added' ? 'Added to compare' : 'Removed from compare', r === 'full' ? 'info' : 'success');
                }} />
                <SmallAction icon="message" label="Ask seller" onPress={() => router.push({ pathname: '/ask/[id]', params: { id: String(p.id), title: p.title } })} />
                <SmallAction icon="external" label="Share" onPress={() => void Share.share({ message: `${p.title} — ${p.share_url}`, url: p.share_url })} />
                {p.accepts_offers ? <SmallAction icon="tag" label="Make offer" onPress={() => router.push({ pathname: '/offer/[id]', params: { id: String(p.id) } })} /> : null}
              </View>

              {p.subscription ? (
                <Press onPress={() => router.push({ pathname: '/subscribe/[id]', params: { id: String(p.id) } })} style={[styles.box, styles.inline, { borderColor: c.line, backgroundColor: c.surface }]}>
                  <Icon name="refresh" size={20} />
                  <View style={{ flex: 1 }}>
                    <Text variant="smallStrong">Subscribe & Save {p.subscription.pct}%</Text>
                    <Text variant="small" tone="muted">
                      Regular delivery. Skip or cancel anytime.
                    </Text>
                  </View>
                  <Icon name="chevron-right" size={20} color={c.muted} />
                </Press>
              ) : null}

              {p.seller ? (
                <Press onPress={() => router.push({ pathname: '/store/[slug]', params: { slug: p.seller!.slug } })} scaleTo={0.98} style={[styles.box, styles.inline, { borderColor: c.line, backgroundColor: c.surface }]}>
                  {p.seller.logo ? (
                    <Image source={p.seller.logo} style={styles.logo} contentFit="contain" />
                  ) : (
                    <View style={[styles.logo, { backgroundColor: palette.charcoal, alignItems: 'center', justifyContent: 'center' }]}>
                      <Text variant="h3" style={{ color: '#fff' }}>
                        {p.seller.name.charAt(0).toUpperCase()}
                      </Text>
                    </View>
                  )}
                  <View style={{ flex: 1 }}>
                    <Text variant="bodyStrong">{p.seller.name}</Text>
                    <Text variant="small" tone="muted">
                      {[p.seller.tier, p.seller.rating > 0 ? `${p.seller.rating.toFixed(1)}★ (${p.seller.rating_count})` : null, p.seller.reply_time].filter(Boolean).join(' · ')}
                    </Text>
                  </View>
                  <Icon name="chevron-right" size={20} color={c.muted} />
                </Press>
              ) : null}

              <View style={[styles.box, { borderColor: c.line, backgroundColor: c.surface, flexDirection: 'row', flexWrap: 'wrap', rowGap: 10 }]}>
                {[
                  ['shield', 'Payment held until delivery'],
                  ['sparkles', 'Cashback on delivery'],
                  ['refresh', `${p.returns.window_days}-day returns`],
                  ['check', 'Verified seller'],
                ].map(([icon, label]) => (
                  <View key={label} style={[styles.inline, { width: '50%' }]}>
                    <Icon name={icon} size={16} />
                    <Text variant="small" style={{ flex: 1 }}>
                      {label}
                    </Text>
                  </View>
                ))}
              </View>
            </>
          )}
        </View>

        {p?.bundles.length ? (
          <>
            <Gap size={24} />
            <SectionTitle title="Bundle and save" />
            {p.bundles.map((b) => (
              <BundleCard key={b.id} bundle={b} />
            ))}
          </>
        ) : null}

        {p?.description_html ? (
          <>
            <Gap size={28} />
            <SectionTitle title="Description" />
            <View style={{ paddingHorizontal: GUTTER }}>
              <RenderHtml
                contentWidth={width - GUTTER * 2}
                source={{ html: p.description_html }}
                baseStyle={{ color: c.fg, fontFamily: font.regular, fontSize: 15, lineHeight: 23 }}
                systemFonts={[font.regular, font.semibold, font.bold]}
                tagsStyles={{
                  strong: { fontFamily: font.bold },
                  b: { fontFamily: font.bold },
                  h2: { fontFamily: font.bold, fontSize: 18, marginTop: 14 },
                  h3: { fontFamily: font.bold, fontSize: 16, marginTop: 12 },
                  a: { color: c.fg, textDecorationLine: 'underline' },
                  blockquote: { borderLeftWidth: 3, borderLeftColor: c.line, paddingLeft: 12, color: c.muted, fontStyle: 'italic' },
                  li: { marginBottom: 4 },
                }}
              />
            </View>
          </>
        ) : null}

        {p?.attributes.length ? (
          <>
            <Gap size={24} />
            <SectionTitle title="Specifications" />
            <View style={[styles.table, { borderColor: c.line, marginHorizontal: GUTTER }]}>
              {p.attributes.map((a, i) => (
                <View key={i} style={[styles.tr, { backgroundColor: i % 2 ? c.surface : c.surface2 }]}>
                  <Text variant="small" tone="muted" style={{ flex: 1 }}>
                    {a.name}
                  </Text>
                  <Text variant="smallStrong" style={{ flex: 1.4 }}>
                    {a.value}
                  </Text>
                </View>
              ))}
            </View>
          </>
        ) : null}

        {p?.fitments.length ? (
          <>
            <Gap size={24} />
            <SectionTitle title="Vehicle fitment" subtitle={`${p.fitments.length} ${p.fitments.length === 1 ? 'vehicle' : 'vehicles'}`} />
            <FitmentTable rows={p.fitments} />
          </>
        ) : null}

        {p ? (
          <>
            <Gap size={28} />
            <ReviewsPreview product={p} />
            <Gap size={28} />
            <QaPreview product={p} />
          </>
        ) : null}

        {related.data?.frequently_bought.length ? (
          <>
            <Gap />
            <SectionTitle title="Frequently bought together" />
            <ProductRail products={related.data.frequently_bought} />
          </>
        ) : null}
        {related.data?.also_viewed.length ? (
          <>
            <Gap />
            <SectionTitle title="Shoppers also viewed" />
            <ProductRail products={related.data.also_viewed} />
          </>
        ) : null}
        {related.data?.related.length ? (
          <>
            <Gap />
            <SectionTitle title="More like this" />
            <ProductRail products={related.data.related} />
          </>
        ) : null}
      </Animated.ScrollView>

      {/* Top bar: back, title (after scrolling), heart */}
      <Animated.View style={[styles.topBar, { paddingTop: insets.top + 4, height: insets.top + 56 }, bar]}>
        <RoundButton icon="arrow-left" label="Back" onPress={() => (router.canGoBack() ? router.back() : router.replace('/'))} />
        <Animated.View style={[{ flex: 1, paddingHorizontal: 8 }, barTitle]}>
          <Text variant="smallStrong" numberOfLines={1}>
            {p?.title}
          </Text>
        </Animated.View>
        {p ? <SaveButton product={p} /> : <View style={{ width: 40 }} />}
      </Animated.View>

      {p ? <BuyBar product={p} variantId={variantId} qty={qty} soldOut={soldOut} paused={paused} price={(sale ?? price) * qty} /> : null}
    </View>
  );
}

function fmtDate(iso: string) {
  const d = new Date(iso.length <= 10 ? iso + 'T12:00:00' : iso);
  return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
}

/** "Does this fit my car?" — green tick drawn in when it fits the garage vehicle; otherwise a prompt. */
function FitCheck({ product: p }: { product: ProductDetail }) {
  const { c } = useTheme();
  const vehicle = useGarage((s) => s.vehicle);
  const fits = p.fits_garage;
  const tick = useSharedValue(0);

  useEffect(() => {
    tick.value = 0;
    if (fits) tick.value = withSpring(1, spring.pop);
  }, [fits, tick]);
  const tickStyle = useAnimatedStyle(() => ({ transform: [{ scale: tick.value }, { rotate: `${(1 - tick.value) * -45}deg` }] }));

  const tone = fits ? { bg: c.successBg, fg: c.success } : vehicle && fits === false ? { bg: c.warningBg, fg: c.warning } : { bg: c.surface, fg: c.fg };

  return (
    <Press onPress={() => router.push('/garage')} scaleTo={0.98} style={[styles.fit, { backgroundColor: tone.bg, borderColor: fits ? 'transparent' : c.line }]} accessibilityLabel="Check the vehicle fit">
      <Animated.View style={[styles.fitIcon, { backgroundColor: fits ? c.success : c.surface2 }, fits ? tickStyle : null]}>
        <Icon name={fits ? 'check' : 'vehicle'} size={18} color={fits ? c.bg : c.fg} strokeWidth={fits ? 3 : 2} />
      </Animated.View>
      <View style={{ flex: 1 }}>
        <Text variant="smallStrong" style={{ color: tone.fg }}>
          {fits ? `Fits your ${vehicle?.label ?? 'vehicle'}` : vehicle && fits === false ? `Not listed for your ${vehicle.label}` : vehicle ? 'Fitment not listed' : 'Does it fit your vehicle?'}
        </Text>
        <Text variant="small" tone="muted">
          {fits ? 'Covered by the Fit Guarantee.' : vehicle ? 'Check the fitment list below, or change vehicle.' : 'Add your vehicle to check.'}
        </Text>
      </View>
      <Icon name="chevron-right" size={18} color={c.muted} />
    </Press>
  );
}

function Stepper({ value, max, onChange }: { value: number; max: number; onChange: (n: number) => void }) {
  const { c } = useTheme();
  return (
    <View style={[styles.stepper, { borderColor: c.line }]}>
      <Press onPress={() => onChange(Math.max(1, value - 1))} haptic="selection" style={styles.stepBtn} accessibilityLabel="Fewer">
        <Icon name="minus" size={16} />
      </Press>
      <Animated.Text key={value} entering={FadeIn.duration(150)} style={{ minWidth: 28, textAlign: 'center', color: c.fg, fontFamily: font.bold, fontSize: 15 }}>
        {value}
      </Animated.Text>
      <Press onPress={() => onChange(Math.min(max, value + 1))} haptic="selection" style={styles.stepBtn} accessibilityLabel="More">
        <Icon name="plus" size={16} />
      </Press>
    </View>
  );
}

function SmallAction({ icon, label, onPress }: { icon: string; label: string; onPress: () => void }) {
  const { c } = useTheme();
  return (
    <Press onPress={onPress} style={[styles.smallAction, { backgroundColor: c.surface, borderColor: c.line }]} accessibilityLabel={label}>
      <Icon name={icon} size={18} />
      <Text variant="small" style={{ fontSize: 11.5 }}>
        {label}
      </Text>
    </Press>
  );
}

function RoundButton({ icon, label, onPress }: { icon: string; label: string; onPress: () => void }) {
  return (
    <Press onPress={onPress} style={styles.round} accessibilityLabel={label}>
      <Icon name={icon} size={20} color="#fff" />
    </Press>
  );
}

function SaveButton({ product }: { product: ProductDetail }) {
  const toggle = useToggleWishlist();
  const pop = useSharedValue(1);
  const style = useAnimatedStyle(() => ({ transform: [{ scale: pop.value }] }));

  return (
    <Press
      haptic="medium"
      onPress={() => {
        pop.value = 0.6;
        pop.value = withSpring(1, spring.pop);
        toggle.mutate(product);
      }}
      style={styles.round}
      accessibilityLabel={product.saved ? 'Remove from saved' : 'Save for later'}>
      <Animated.View style={style}>
        <Icon name="heart" size={20} color={product.saved ? palette.red : '#fff'} fill={product.saved ? palette.red : 'none'} />
      </Animated.View>
    </Press>
  );
}

function BundleCard({ bundle: b }: { bundle: ProductDetail['bundles'][number] }) {
  const { c } = useTheme();
  return (
    <View style={[styles.box, { borderColor: c.line, backgroundColor: c.surface, marginHorizontal: GUTTER }]}>
      <Text variant="bodyStrong">{b.title}</Text>
      <View style={{ flexDirection: 'row', gap: 8, marginTop: 10 }}>
        {b.products.slice(0, 4).map((x) => (
          <Image key={x.id} source={x.image} style={{ width: 56, height: 56, borderRadius: 10, backgroundColor: c.surface2 }} contentFit="cover" />
        ))}
      </View>
      <View style={[styles.inline, { marginTop: 10 }]}>
        <Text variant="price">{money(b.price)}</Text>
        <Text variant="small" tone="muted" style={{ textDecorationLine: 'line-through' }}>
          {money(b.full_price)}
        </Text>
        <Text variant="smallStrong" tone="success">
          Save {money(b.savings)}
        </Text>
      </View>
    </View>
  );
}

function FitmentTable({ rows }: { rows: ProductDetail['fitments'] }) {
  const { c } = useTheme();
  const [all, setAll] = useState(false);
  const shown = all ? rows : rows.slice(0, 8);

  return (
    <View style={{ marginHorizontal: GUTTER }}>
      <View style={[styles.table, { borderColor: c.line }]}>
        {shown.map((r, i) => (
          <View key={`${r.vehicle_id}-${i}`} style={[styles.tr, { backgroundColor: i % 2 ? c.surface : c.surface2 }]}>
            <Text variant="smallStrong" style={{ width: 48 }}>
              {r.year}
            </Text>
            <Text variant="small" style={{ flex: 1 }}>
              {r.make} {r.model}
              {r.trim ? ` ${r.trim}` : ''}
            </Text>
            <Text variant="small" tone="muted" style={{ flex: 0.9, textAlign: 'right' }} numberOfLines={1}>
              {r.engine ?? r.position ?? '—'}
            </Text>
          </View>
        ))}
      </View>
      {rows.length > 8 ? <Button title={all ? 'Show less' : `Show all ${rows.length}`} kind="ghost" size="sm" onPress={() => setAll(!all)} /> : null}
    </View>
  );
}

/** The sticky buy bar. Slides up once the product has loaded; Add to cart sends the photo flying. */
function BuyBar({ product: p, variantId, qty, soldOut, paused, price }: { product: ProductDetail; variantId: number | null; qty: number; soldOut: boolean; paused: boolean; price: number }) {
  const { c, dark } = useTheme();
  const insets = useSafeAreaInsets();
  const add = useAddToCart();
  const anchor = useRef<HostView>(null);
  const y = useSharedValue(120);

  useEffect(() => {
    y.value = withSpring(0, spring.gentle);
  }, [y]);
  const style = useAnimatedStyle(() => ({ transform: [{ translateY: y.value }] }));

  const addIt = (then?: () => void) =>
    add.mutate({ productId: p.id, variantId, quantity: qty, from: anchor.current, image: p.image }, { onSuccess: () => then?.() });

  return (
    <Animated.View style={[styles.buy, { paddingBottom: insets.bottom + 10, backgroundColor: dark ? 'rgba(21,25,30,0.97)' : 'rgba(255,255,255,0.97)', borderTopColor: c.line }, style]}>
      <View ref={anchor} collapsable={false} style={{ position: 'absolute', left: 60, top: -80, width: 80, height: 80 }} pointerEvents="none" />
      {soldOut || paused ? (
        <Button
          title={paused ? 'Seller is away' : 'Email me when it\'s back'}
          icon={paused ? 'clock' : 'bell'}
          kind={paused ? 'outline' : 'accent'}
          block
          style={{ flex: 1 }}
          onPress={paused ? undefined : () => toast('We\'ll let you know when it\'s back.', 'info')}
          disabled={paused}
        />
      ) : (
        <>
          <Button title="Add to cart" icon="cart" kind="outline" loading={add.isPending} onPress={() => addIt()} style={{ flex: 1 }} />
          <Button title={`Buy now · ${money(price)}`} kind="accent" onPress={() => addIt(() => router.navigate('/cart'))} style={{ flex: 1.15 }} />
        </>
      )}
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  sheet: { marginTop: -24, borderTopLeftRadius: 24, borderTopRightRadius: 24, paddingHorizontal: GUTTER, paddingTop: 22 },
  metaRow: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 10, marginTop: 8 },
  deal: { marginTop: 12, borderRadius: radius.md, backgroundColor: palette.red, padding: 12, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  inline: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  stockDot: { width: 8, height: 8, borderRadius: 4 },
  note: { flexDirection: 'row', gap: 8, padding: 10, borderRadius: radius.md, alignItems: 'flex-start' },
  fit: { marginTop: 16, flexDirection: 'row', alignItems: 'center', gap: 12, padding: 12, borderRadius: radius.lg, borderWidth: 1 },
  fitIcon: { width: 36, height: 36, borderRadius: 18, alignItems: 'center', justifyContent: 'center' },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 10 },
  chip: { flexDirection: 'row', alignItems: 'center', gap: 8, borderWidth: 1.5, borderRadius: radius.md, paddingHorizontal: 12, paddingVertical: 8 },
  chipImg: { width: 26, height: 26, borderRadius: 6 },
  stepper: { flexDirection: 'row', alignItems: 'center', borderWidth: 1, borderRadius: radius.md },
  stepBtn: { width: 40, height: 40, alignItems: 'center', justifyContent: 'center' },
  box: { marginTop: 16, borderWidth: 1, borderRadius: radius.lg, padding: 14, gap: 4 },
  actions: { flexDirection: 'row', gap: 8, marginTop: 20 },
  smallAction: { flex: 1, alignItems: 'center', gap: 4, paddingVertical: 10, borderRadius: radius.md, borderWidth: 1 },
  logo: { width: 44, height: 44, borderRadius: 12, overflow: 'hidden' },
  table: { borderWidth: 1, borderRadius: radius.md, overflow: 'hidden' },
  tr: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: 12, paddingVertical: 10, gap: 8 },
  topBar: { position: 'absolute', top: 0, left: 0, right: 0, flexDirection: 'row', alignItems: 'center', paddingHorizontal: 12 },
  round: { width: 40, height: 40, borderRadius: 20, backgroundColor: 'rgba(21,25,30,0.55)', alignItems: 'center', justifyContent: 'center' },
  buy: { position: 'absolute', left: 0, right: 0, bottom: 0, flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: GUTTER, paddingTop: 12, borderTopWidth: StyleSheet.hairlineWidth },
});
