import { useQuery, useQueryClient } from '@tanstack/react-query';
import { LinearGradient } from 'expo-linear-gradient';
import { router, type Href } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { Alert, Linking, ScrollView, StyleSheet, View } from 'react-native';
import Animated, { FadeInDown } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { get, post } from '@/api/client';
import type { User } from '@/api/types';
import { usePrefs } from '@/state/prefs';
import { useGarage, useSession } from '@/state/session';
import { Button } from '@/ui/Button';
import { Icon } from '@/ui/Icon';
import { Logo } from '@/ui/Logo';
import { Press } from '@/ui/Press';
import { GUTTER } from '@/ui/Section';
import { TAB_BAR_HEIGHT } from '@/ui/TabBar';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { STAGGER } from '@/theme/motion';
import { useTheme } from '@/theme/theme';
import { palette, radius } from '@/theme/tokens';

type Item = { icon: string; label: string; to?: Href; web?: string; badge?: number; guest?: boolean; onPress?: () => void };

export default function AccountScreen() {
  const { c, dark } = useTheme();
  const insets = useSafeAreaInsets();
  const qc = useQueryClient();
  const { token, user, setUser, signOut } = useSession();
  const vehicle = useGarage((s) => s.vehicle);
  const compare = usePrefs((s) => s.compare);
  const toggleScheme = usePrefs((s) => s.toggleScheme);

  // Keep the profile fresh (Plus status, name) whenever the tab is opened.
  useQuery({
    queryKey: ['me'],
    queryFn: async () => {
      const r = await get<{ user: User }>('/me');
      setUser(r.user);
      return r.user;
    },
    enabled: !!token,
  });
  const unread = useQuery({
    queryKey: ['messages-unread'],
    queryFn: () => get<{ unread_total: number }>('/messages').then((r) => r.unread_total),
    enabled: !!token,
    refetchInterval: 60_000,
  });

  const groups: { title: string; items: Item[] }[] = [
    {
      title: 'Shopping',
      items: [
        { icon: 'package', label: 'Orders', to: '/orders' },
        { icon: 'message', label: 'Messages', to: '/messages', badge: unread.data ?? 0 },
        { icon: 'heart', label: 'Saved parts', to: '/wishlist', guest: true },
        { icon: 'vehicle', label: vehicle ? `Garage · ${vehicle.label}` : 'Garage', to: '/garage', guest: true },
        { icon: 'grid', label: `Compare${compare.length ? ` (${compare.length})` : ''}`, to: '/compare', guest: true },
        { icon: 'truck', label: 'Track an order', to: '/track', guest: true },
      ],
    },
    {
      title: 'Money',
      items: [
        { icon: 'dollar', label: 'Wallet & cashback', to: '/wallet' },
        { icon: 'sparkles', label: 'Panmotive Plus', to: '/plus', guest: true },
        { icon: 'refresh', label: 'Subscribe & Save', to: '/subscriptions' },
        { icon: 'users', label: 'Affiliate links', to: '/affiliates' },
      ],
    },
    {
      title: 'Settings',
      items: [
        { icon: 'bell', label: 'Notifications & emails', to: '/preferences' },
        { icon: dark ? 'sun' : 'moon', label: dark ? 'Light mode' : 'Dark mode', guest: true, onPress: toggleScheme },
        { icon: 'user', label: 'Profile & password', to: '/profile' },
      ],
    },
    {
      title: 'Help',
      items: [
        { icon: 'shield', label: 'Fit Guarantee', to: '/fit-guarantee', guest: true },
        { icon: 'help', label: 'Email support', guest: true, onPress: () => void Linking.openURL('mailto:support@panmotive.com') },
        { icon: 'info', label: 'Privacy policy', web: 'https://panmotive.com/privacy', guest: true },
        { icon: 'list', label: 'Terms', web: 'https://panmotive.com/terms', guest: true },
      ],
    },
  ];

  const open = (it: Item) => {
    if (it.onPress) return it.onPress();
    if (!token && !it.guest) return router.push('/sign-in');
    if (it.web) return void WebBrowser.openBrowserAsync(it.web, { toolbarColor: '#15191E', controlsColor: '#E51B2B' });
    if (it.to) router.push(it.to);
  };

  const doSignOut = () =>
    Alert.alert('Sign out?', 'Your cart and saved parts stay on your account.', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Sign out',
        style: 'destructive',
        onPress: async () => {
          await post('/auth/logout').catch(() => {});
          await signOut();
          qc.clear();
          toast('Signed out', 'info');
        },
      },
    ]);

  return (
    <ScrollView style={{ flex: 1, backgroundColor: c.bg }} contentContainerStyle={{ paddingBottom: TAB_BAR_HEIGHT + insets.bottom + 40 }}>
      {/* Header card: charcoal with the red forward cut, like the website's account band. */}
      <View style={[styles.hero, { paddingTop: insets.top + 20 }]}>
        <LinearGradient colors={['#15191E', '#222830']} style={StyleSheet.absoluteFill} />
        <View style={styles.cut} />
        {user ? (
          <Animated.View entering={FadeInDown.springify()} style={{ flexDirection: 'row', alignItems: 'center', gap: 14 }}>
            <View style={styles.avatar}>
              <Text variant="h2" style={{ color: '#fff' }}>
                {user.name.charAt(0).toUpperCase()}
              </Text>
            </View>
            <View style={{ flex: 1 }}>
              <Text variant="h2" style={{ color: '#fff' }} numberOfLines={1}>
                {user.name}
              </Text>
              <Text variant="small" style={{ color: 'rgba(255,255,255,0.75)' }} numberOfLines={1}>
                {user.email}
              </Text>
              {user.plus ? (
                <View style={styles.plus}>
                  <Icon name="sparkles" size={14} color="#fff" />
                  <Text variant="smallStrong" style={{ color: '#fff', fontSize: 11 }}>
                    Plus member
                  </Text>
                </View>
              ) : null}
            </View>
          </Animated.View>
        ) : (
          <Animated.View entering={FadeInDown.springify()}>
            <Logo height={24} wordColor="#fff" />
            <Text variant="h2" style={{ color: '#fff', marginTop: 16 }}>
              Sign in for the full Panmotive
            </Text>
            <Text variant="small" style={{ color: 'rgba(255,255,255,0.75)', marginTop: 4 }}>
              Track orders, message sellers and earn cashback.
            </Text>
            <View style={{ flexDirection: 'row', gap: 10, marginTop: 16 }}>
              <Button title="Sign in" onPress={() => router.push('/sign-in')} style={{ flex: 1 }} />
              <Button title="Create account" kind="outline" onPress={() => router.push({ pathname: '/sign-in', params: { mode: 'register' } })} style={{ flex: 1, borderColor: 'rgba(255,255,255,0.3)' }} />
            </View>
          </Animated.View>
        )}
      </View>

      {groups.map((g, gi) => (
        <Animated.View key={g.title} entering={FadeInDown.delay((gi + 1) * STAGGER * 2).springify()} style={{ marginTop: 22, paddingHorizontal: GUTTER }}>
          <Text variant="tiny" tone="muted" style={{ marginBottom: 8, marginLeft: 4 }}>
            {g.title}
          </Text>
          <View style={[styles.group, { backgroundColor: c.surface, borderColor: c.line }]}>
            {g.items.map((it, i) => (
              <Press key={it.label} onPress={() => open(it)} scaleTo={0.98} haptic="selection" style={[styles.row, i > 0 && { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: c.line }]}>
                <View style={[styles.rowIcon, { backgroundColor: c.surface2 }]}>
                  <Icon name={it.icon} size={18} />
                </View>
                <Text variant="bodyStrong" style={{ flex: 1 }} numberOfLines={1}>
                  {it.label}
                </Text>
                {it.badge ? (
                  <View style={styles.badge}>
                    <Text variant="smallStrong" style={{ color: '#fff', fontSize: 11 }}>
                      {it.badge}
                    </Text>
                  </View>
                ) : null}
                {!token && !it.guest ? <Icon name="user" size={14} color={c.muted} /> : null}
                <Icon name={it.web ? 'external' : 'chevron-right'} size={16} color={c.muted} />
              </Press>
            ))}
          </View>
        </Animated.View>
      ))}

      {token ? (
        <View style={{ paddingHorizontal: GUTTER, marginTop: 24, gap: 10 }}>
          <Button title="Sign out" kind="outline" onPress={doSignOut} />
          <Button title="Delete account" kind="ghost" onPress={() => router.push('/delete-account')} />
        </View>
      ) : null}

      <Text variant="small" tone="muted" center style={{ marginTop: 24, fontSize: 11 }}>
        Panmotive · Independent sellers across the US
      </Text>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  hero: { paddingHorizontal: GUTTER, paddingBottom: 26, overflow: 'hidden' },
  cut: { position: 'absolute', right: -40, top: -40, bottom: -40, width: 90, backgroundColor: palette.red, transform: [{ skewX: '-16deg' }] },
  avatar: { width: 60, height: 60, borderRadius: 18, backgroundColor: palette.red, alignItems: 'center', justifyContent: 'center' },
  plus: { flexDirection: 'row', alignItems: 'center', gap: 4, alignSelf: 'flex-start', marginTop: 6, backgroundColor: 'rgba(229,27,43,0.9)', borderRadius: 999, paddingHorizontal: 8, paddingVertical: 2 },
  group: { borderWidth: 1, borderRadius: radius.lg, overflow: 'hidden' },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 14, height: 56 },
  rowIcon: { width: 34, height: 34, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  badge: { minWidth: 20, height: 20, borderRadius: 10, backgroundColor: palette.red, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 6 },
});
