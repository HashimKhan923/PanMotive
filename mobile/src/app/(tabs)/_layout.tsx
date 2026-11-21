import { Redirect, Tabs } from 'expo-router';

import { usePrefs } from '@/state/prefs';

import { TabBar } from '@/ui/TabBar';

/** The five tabs: Home, Search, Deals, Cart, Account — with the brand's floating animated tab bar. */
export default function TabsLayout() {
  // First launch: the welcome slides first.
  const onboarded = usePrefs((s) => s.onboarded);
  if (!onboarded) return <Redirect href="/welcome" />;

  return (
    <Tabs tabBar={(props) => <TabBar {...props} />} screenOptions={{ headerShown: false, animation: 'shift' }}>
      <Tabs.Screen name="index" />
      <Tabs.Screen name="search" />
      <Tabs.Screen name="deals" />
      <Tabs.Screen name="cart" />
      <Tabs.Screen name="account" />
    </Tabs>
  );
}
