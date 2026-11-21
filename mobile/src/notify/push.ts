import Constants from 'expo-constants';
import * as Notifications from 'expo-notifications';
import { useEffect } from 'react';
import { Platform } from 'react-native';
import { create } from 'zustand';
import { persist } from 'zustand/middleware';

import { del, post } from '@/api/client';
import type { AppLink } from '@/api/types';
import { openLink } from '@/nav/links';
import { useSession } from '@/state/session';
import { persistStorage } from '@/state/storage';
import { toast } from '@/ui/Toast';

/**
 * Push notifications: shipped, delivered, price drops, replies… (the server sends them through Expo).
 * The phone's token is registered with Panmotive while the shopper is signed in; tapping a
 * notification opens the matching screen.
 */
Notifications.setNotificationHandler({
  handleNotification: async () => ({ shouldShowBanner: true, shouldShowList: true, shouldPlaySound: true, shouldSetBadge: false }),
});

type PushState = { enabled: boolean; token: string | null; set: (p: Partial<Pick<PushState, 'enabled' | 'token'>>) => void };

const usePushState = create<PushState>()(
  persist((set) => ({ enabled: false, token: null, set: (p) => set(p) }), { name: 'pm-push', storage: persistStorage, partialize: (s) => ({ enabled: s.enabled, token: s.token }) }),
);

async function getToken(): Promise<string | null> {
  if (Platform.OS === 'web') return null;
  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync('orders', {
      name: 'Orders and alerts',
      importance: Notifications.AndroidImportance.HIGH,
      lightColor: '#E51B2B',
      vibrationPattern: [0, 180, 120, 180],
    });
  }
  const perm = await Notifications.getPermissionsAsync();
  const status = perm.granted ? 'granted' : (await Notifications.requestPermissionsAsync()).status;
  if (status !== 'granted') return null;

  const projectId = (Constants.expoConfig?.extra as { eas?: { projectId?: string } } | undefined)?.eas?.projectId ?? Constants.easConfig?.projectId;
  if (!projectId) return null; // set once the app is registered with EAS (Expo's build service)
  return (await Notifications.getExpoPushTokenAsync({ projectId })).data;
}

/** Turn notifications on/off for this phone (Account → Notifications). */
export function usePush() {
  const { enabled, set } = usePushState();
  const signedIn = !!useSession((s) => s.token);

  const toggle = async () => {
    if (enabled) {
      const token = usePushState.getState().token;
      if (token && signedIn) await del('/push-tokens', { token }).catch(() => {});
      set({ enabled: false });
      toast('Notifications off for this phone', 'info');
      return;
    }
    const token = await getToken().catch(() => null);
    if (!token) {
      toast('Turn on notifications for Panmotive in your phone settings.', 'info');
      return;
    }
    if (signedIn) await post('/push-tokens', { token, platform: Platform.OS }).catch(() => {});
    set({ enabled: true, token });
    toast('Notifications on');
  };

  return { enabled, toggle };
}

/** Mounted once in the root layout: re-registers after sign-in and routes notification taps. */
export function usePushBridge() {
  const signedIn = !!useSession((s) => s.token);
  const { enabled, token } = usePushState();

  useEffect(() => {
    if (signedIn && enabled && token) void post('/push-tokens', { token, platform: Platform.OS }).catch(() => {});
  }, [signedIn, enabled, token]);

  useEffect(() => {
    if (Platform.OS === 'web') return;
    const open = (data: unknown) => {
      const link = (data as { link?: AppLink } | undefined)?.link;
      if (link) setTimeout(() => openLink(link), 300);
    };
    // Opened the app by tapping a notification.
    void Notifications.getLastNotificationResponseAsync().then((r) => r && open(r.notification.request.content.data));
    const sub = Notifications.addNotificationResponseReceivedListener((r) => open(r.notification.request.content.data));
    return () => sub.remove();
  }, []);
}
