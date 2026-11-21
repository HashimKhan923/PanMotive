import { create } from 'zustand';
import { persist } from 'zustand/middleware';

import { persistStorage } from '@/state/storage';
import type { ColorScheme } from '@/theme/tokens';

type Prefs = {
  scheme: ColorScheme;
  onboarded: boolean;
  recentSearches: string[];
  /** Product ids, newest first (max 12) — like the website's recently viewed row. */
  recentlyViewed: number[];
  /** Up to 4 products to compare (the website's limit). */
  compare: number[];
  setScheme: (s: ColorScheme) => void;
  toggleScheme: () => void;
  setOnboarded: () => void;
  addSearch: (q: string) => void;
  clearSearches: () => void;
  viewed: (id: number) => void;
  toggleCompare: (id: number) => 'added' | 'removed' | 'full';
  clearCompare: () => void;
};

export const COMPARE_MAX = 4;

export const usePrefs = create<Prefs>()(
  persist(
    (set, get) => ({
      scheme: 'dark',
      onboarded: false,
      recentSearches: [],
      recentlyViewed: [],
      compare: [],
      setScheme: (scheme) => set({ scheme }),
      toggleScheme: () => set({ scheme: get().scheme === 'dark' ? 'light' : 'dark' }),
      setOnboarded: () => set({ onboarded: true }),
      addSearch: (q) => {
        const term = q.trim();
        if (term.length < 2) return;
        set({ recentSearches: [term, ...get().recentSearches.filter((s) => s.toLowerCase() !== term.toLowerCase())].slice(0, 8) });
      },
      clearSearches: () => set({ recentSearches: [] }),
      viewed: (id) => set({ recentlyViewed: [id, ...get().recentlyViewed.filter((x) => x !== id)].slice(0, 12) }),
      toggleCompare: (id) => {
        const list = get().compare;
        if (list.includes(id)) {
          set({ compare: list.filter((x) => x !== id) });
          return 'removed';
        }
        if (list.length >= COMPARE_MAX) return 'full';
        set({ compare: [...list, id] });
        return 'added';
      },
      clearCompare: () => set({ compare: [] }),
    }),
    { name: 'pm-prefs', storage: persistStorage },
  ),
);
