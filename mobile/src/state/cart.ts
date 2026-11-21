import { useQuery, useQueryClient } from '@tanstack/react-query';

import { get } from '@/api/client';
import type { Cart } from '@/api/types';

export const CART_KEY = ['cart'] as const;

/** The cart, shared by every screen (tab badge, cart, checkout). */
export function useCart(params?: { coupon?: string | null; state?: string | null }) {
  return useQuery({
    queryKey: [...CART_KEY, params?.coupon ?? null, params?.state ?? null],
    queryFn: ({ signal }) => get<Cart>('/cart', { coupon: params?.coupon ?? undefined, state: params?.state ?? undefined }, signal),
    staleTime: 15_000,
  });
}

export function useCartCount(): number {
  return useCart().data?.count ?? 0;
}

/** After the server answers with a fresh cart (add / update / remove), show it everywhere at once. */
export function useSetCart() {
  const qc = useQueryClient();

  return (cart: Cart) => {
    qc.setQueryData([...CART_KEY, null, null], cart);
    void qc.invalidateQueries({ queryKey: CART_KEY, predicate: (q) => q.queryKey[1] !== null || q.queryKey[2] !== null });
  };
}
