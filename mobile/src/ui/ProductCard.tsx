import { Image } from 'expo-image';
import { router } from 'expo-router';
import { memo, useRef } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated, { FadeInDown, useAnimatedStyle, useSharedValue, withSequence, withSpring, withTiming } from 'react-native-reanimated';

import { useAddToCart, useToggleWishlist } from '@/api/actions';
import type { ProductCard as Card } from '@/api/types';
import { morphFrom } from '@/motion/Morph';
import { usePrefs } from '@/state/prefs';
import { Icon } from '@/ui/Icon';
import { haptic, Press } from '@/ui/Press';
import { Price } from '@/ui/Price';
import { Rating } from '@/ui/Rating';
import type { HostView } from '@/ui/host';
import { Text } from '@/ui/Text';
import { spring, STAGGER } from '@/theme/motion';
import { useTheme } from '@/theme/theme';
import { palette, radius } from '@/theme/tokens';

type Props = { product: Card; width: number; index?: number };

/**
 * The product card, same content and rules as the website's: live-deal price only, "Fits <make model>"
 * instead of the brand, stock warnings, the seller's rating when the part has no reviews yet.
 * Pressing it tilts it back slightly; opening it morphs the photo into the product page.
 */
export const ProductCard = memo(function ProductCard({ product: p, width, index = 0 }: Props) {
  const { c } = useTheme();
  const imgRef = useRef<HostView>(null);
  const add = useAddToCart();
  const viewed = usePrefs((s) => s.viewed);

  const open = () => {
    viewed(p.id);
    morphFrom(imgRef.current, p.image_large ?? p.image, () => router.push({ pathname: '/product/[slug]', params: { slug: p.slug, img: p.image_large ?? p.image ?? '' } }));
  };

  return (
    <Animated.View entering={FadeInDown.delay(Math.min(index, 8) * STAGGER).springify().damping(18).stiffness(160)} style={{ width }}>
      <Press onPress={open} scaleTo={0.97} haptic={false} accessibilityRole="link" accessibilityLabel={`${p.title}, $${p.effective_price.toFixed(2)}`}>
        <View style={[styles.card, { backgroundColor: c.surface, borderColor: c.line }]}>
          <View ref={imgRef} collapsable={false} style={{ width: width - 2, height: width - 2, backgroundColor: c.surface2 }}>
            {p.image ? (
              <Image source={p.image} style={StyleSheet.absoluteFill} contentFit="cover" transition={260} recyclingKey={String(p.id)} />
            ) : (
              <View style={[StyleSheet.absoluteFill, styles.center]}>
                <Icon name="image" size={32} color={c.muted} />
              </View>
            )}
            {p.off_pct > 0 && (
              <View style={[styles.off, { backgroundColor: palette.red }]}>
                <Text variant="smallStrong" style={{ color: '#fff', fontSize: 11 }}>
                  −{p.off_pct}%
                </Text>
              </View>
            )}
            <Heart product={p} />
            {!p.in_stock && !p.has_variants ? (
              <View style={styles.soldOut}>
                <Text variant="smallStrong" style={{ color: '#fff', fontSize: 11 }}>
                  Out of stock
                </Text>
              </View>
            ) : p.stock > 0 && p.stock <= 3 && !p.has_variants ? (
              <View style={[styles.low, { backgroundColor: c.warningBg }]}>
                <Text variant="smallStrong" style={{ color: c.warning, fontSize: 11 }}>
                  Only {p.stock} left
                </Text>
              </View>
            ) : null}
          </View>

          <View style={styles.body}>
            <View style={styles.meta}>
              {p.fits_garage ? <Icon name="check" size={16} color={c.success} /> : null}
              <Text variant="tiny" tone={p.fits_garage ? 'success' : 'muted'} numberOfLines={1} style={{ flexShrink: 1, fontSize: 10 }}>
                {p.fits_garage ? 'Fits your vehicle' : p.fits_vehicle ? `Fits ${p.fits_vehicle}` : p.part_number ? `Part no. ${p.part_number}` : ' '}
              </Text>
            </View>
            <Text variant="small" numberOfLines={2} style={{ minHeight: 36, marginTop: 4 }}>
              {p.title}
            </Text>
            <View style={{ marginTop: 8 }}>
              <Price price={p.price} sale={p.sale_price} basis={p.basis} />
            </View>
            <View style={styles.foot}>
              <Rating value={p.rating} count={p.rating_count} size={11} />
              {p.quick_add && (
                <Press
                  haptic="medium"
                  scaleTo={0.85}
                  accessibilityLabel={`Add ${p.title} to cart`}
                  onPress={() => add.mutate({ productId: p.id, from: imgRef.current, image: p.image })}
                  style={[styles.add, { backgroundColor: c.fg }]}>
                  <Icon name="plus" size={16} color={c.bg} strokeWidth={2.5} />
                </Press>
              )}
            </View>
          </View>
        </View>
      </Press>
    </Animated.View>
  );
});

/** The heart: springs, and a little red ring bursts out when you save. */
function Heart({ product }: { product: Card }) {
  const toggle = useToggleWishlist();
  const scale = useSharedValue(1);
  const ring = useSharedValue(0);

  const heart = useAnimatedStyle(() => ({ transform: [{ scale: scale.value }] }));
  const burst = useAnimatedStyle(() => ({ opacity: ring.value > 0 && ring.value < 1 ? 1 - ring.value : 0, transform: [{ scale: 0.6 + ring.value * 1.2 }] }));

  return (
    <Press
      haptic={false}
      scaleTo={0.85}
      accessibilityLabel={product.saved ? 'Remove from saved' : 'Save for later'}
      accessibilityState={{ selected: product.saved }}
      onPress={() => {
        haptic(product.saved ? 'light' : 'medium');
        scale.value = withSequence(withSpring(1.35, spring.pop), withSpring(1, spring.pop));
        if (!product.saved) {
          ring.value = 0;
          ring.value = withTiming(1, { duration: 520 });
        }
        toggle.mutate(product);
      }}
      style={styles.heartWrap}>
      <Animated.View style={[styles.ring, burst]} />
      <Animated.View style={heart}>
        <Icon name="heart" size={18} color={product.saved ? palette.red : '#fff'} fill={product.saved ? palette.red : 'none'} />
      </Animated.View>
    </Press>
  );
}

const styles = StyleSheet.create({
  card: { borderRadius: radius.lg, borderWidth: 1, overflow: 'hidden' },
  center: { alignItems: 'center', justifyContent: 'center' },
  off: { position: 'absolute', top: 10, left: 10, borderRadius: 999, paddingHorizontal: 7, paddingVertical: 2 },
  heartWrap: {
    position: 'absolute',
    top: 8,
    right: 8,
    width: 34,
    height: 34,
    borderRadius: 17,
    backgroundColor: 'rgba(21,25,30,0.55)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  ring: { position: 'absolute', width: 34, height: 34, borderRadius: 17, borderWidth: 2, borderColor: palette.red },
  soldOut: { position: 'absolute', left: 0, right: 0, bottom: 0, paddingVertical: 5, alignItems: 'center', backgroundColor: 'rgba(21,25,30,0.85)' },
  low: { position: 'absolute', left: 8, bottom: 8, borderRadius: 4, paddingHorizontal: 6, paddingVertical: 2 },
  body: { padding: 12 },
  meta: { flexDirection: 'row', alignItems: 'center', gap: 4, height: 16 },
  foot: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 8, minHeight: 30 },
  add: { width: 30, height: 30, borderRadius: 15, alignItems: 'center', justifyContent: 'center' },
});
