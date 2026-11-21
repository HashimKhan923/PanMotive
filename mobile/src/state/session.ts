import * as Crypto from 'expo-crypto';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';
import { create } from 'zustand';
import { persist } from 'zustand/middleware';

import { persistStorage } from '@/state/storage';
import type { User, Vehicle } from '@/api/types';

/**
 * Who is using the app. The sign-in token lives in the phone's secure storage (Keychain / Keystore),
 * never in plain storage. The device id is this install's identity for guest cart and wishlist.
 */
const TOKEN_KEY = 'pm.token';

const secure = {
  get: async (): Promise<string | null> =>
    Platform.OS === 'web' ? globalThis.localStorage?.getItem(TOKEN_KEY) ?? null : SecureStore.getItemAsync(TOKEN_KEY),
  set: async (v: string | null) => {
    if (Platform.OS === 'web') {
      v ? globalThis.localStorage?.setItem(TOKEN_KEY, v) : globalThis.localStorage?.removeItem(TOKEN_KEY);
      return;
    }
    v ? await SecureStore.setItemAsync(TOKEN_KEY, v) : await SecureStore.deleteItemAsync(TOKEN_KEY);
  },
};

type Session = {
  ready: boolean;
  token: string | null;
  user: User | null;
  deviceId: string;
  /** Guest order access keys by order number (guest checkout), so they can follow their order. */
  guestOrders: Record<string, string>;
  hydrate: () => Promise<void>;
  signIn: (token: string, user: User) => Promise<void>;
  setUser: (user: User) => void;
  signOut: () => Promise<void>;
  rememberGuestOrder: (number: string, access: string) => void;
};

export const useSession = create<Session>()(
  persist(
    (set, get) => ({
      ready: false,
      token: null,
      user: null,
      deviceId: '',
      guestOrders: {},
      hydrate: async () => {
        const token = await secure.get();
        const deviceId = get().deviceId || Crypto.randomUUID();
        set({ token, deviceId, ready: true, user: token ? get().user : null });
      },
      signIn: async (token, user) => {
        await secure.set(token);
        set({ token, user });
      },
      setUser: (user) => set({ user }),
      signOut: async () => {
        await secure.set(null);
        set({ token: null, user: null });
      },
      rememberGuestOrder: (number, access) => set({ guestOrders: { ...get().guestOrders, [number]: access } }),
    }),
    {
      name: 'pm-session',
      storage: persistStorage,
      // The token is NOT persisted here — only in secure storage.
      partialize: (s) => ({ user: s.user, deviceId: s.deviceId, guestOrders: s.guestOrders }),
    },
  ),
);

type Garage = {
  vehicle: Vehicle | null;
  /** Other vehicles the shopper has picked before, for a quick switch. */
  saved: Vehicle[];
  setVehicle: (v: Vehicle | null) => void;
  forget: (id: number) => void;
};

export const useGarage = create<Garage>()(
  persist(
    (set, get) => ({
      vehicle: null,
      saved: [],
      setVehicle: (vehicle) =>
        set({
          vehicle,
          saved: vehicle ? [vehicle, ...get().saved.filter((v) => v.id !== vehicle.id)].slice(0, 5) : get().saved,
        }),
      forget: (id) =>
        set({ saved: get().saved.filter((v) => v.id !== id), vehicle: get().vehicle?.id === id ? null : get().vehicle }),
    }),
    { name: 'pm-garage', storage: persistStorage },
  ),
);
