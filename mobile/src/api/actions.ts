import { useMutation, useQueryClient, type QueryClient } from '@tanstack/react-query';

import { ApiError, post } from '@/api/client';
import type { Cart, ProductCard } from '@/api/types';
import { flyToCart } from '@/motion/Flight';
import { useSetCart } from '@/state/cart';
import type { HostView } from '@/ui/host';
import { toast } from '@/ui/Toast';

/** Add to cart from anywhere: the photo flies to the cart tab, the badge pops, a toast confirms. */
export function useAddToCart() {
  const setCart = useSetCart();

  return useMutation({
    mutationFn: (v: { productId: number; variantId?: number | null; quantity?: number; from?: HostView | null; image?: string | null }) =>
      post<Cart>('/cart/items', { product_id: v.productId, variant_id: v.variantId ?? undefined, quantity: v.quantity ?? 1 }),
    onMutate: (v) => {
      flyToCart(v.from ?? null, v.image);
    },
    onSuccess: (cart) => {
      setCart(cart);
      toast('Added to cart');
    },
    onError: (e) => toast(e instanceof ApiError ? e.message : 'Couldn\'t add that. Try again.', 'error'),
  });
}

/** Walk every cached product list and flip one product's heart, so the change shows everywhere at once. */
function setSavedEverywhere(qc: QueryClient, productId: number, saved: boolean) {
  qc.setQueriesData({ predicate: () => true }, (old: unknown) => flip(old, productId, saved));
}

function flip(node: unknown, id: number, saved: boolean): unknown {
  if (Array.isArray(node)) return node.map((n) => flip(n, id, saved));
  if (node && typeof node === 'object') {
    const o = node as Record<string, unknown>;
    if (o.id === id && 'saved' in o && 'slug' in o) return { ...o, saved };
    let changed = false;
    const out: Record<string, unknown> = {};
    for (const k of Object.keys(o)) {
      const v = flip(o[k], id, saved);
      if (v !== o[k]) changed = true;
      out[k] = v;
    }
    return changed ? out : node;
  }
  return node;
}

/** Heart a part. Guests can save too (kept on this phone until they sign in). */
export function useToggleWishlist() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (p: Pick<ProductCard, 'id' | 'saved'>) => post<{ saved: boolean; count: number }>('/wishlist/toggle', { product_id: p.id }),
    onMutate: (p) => setSavedEverywhere(qc, p.id, !p.saved),
    onSuccess: (res, p) => {
      setSavedEverywhere(qc, p.id, res.saved);
      void qc.invalidateQueries({ queryKey: ['wishlist'] });
    },
    onError: (_e, p) => {
      setSavedEverywhere(qc, p.id, p.saved);
      toast('Couldn\'t save that. Try again.', 'error');
    },
  });
}
