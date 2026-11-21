import { Inter_400Regular, Inter_500Medium, Inter_600SemiBold, Inter_700Bold, useFonts } from '@expo-google-fonts/inter';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { DarkTheme, DefaultTheme, Stack, ThemeProvider } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import * as SystemUI from 'expo-system-ui';
import { useEffect, useState } from 'react';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { ApiError } from '@/api/client';
import { FlightOverlay } from '@/motion/Flight';
import { ForwardCutOverlay } from '@/motion/ForwardCut';
import { MorphOverlay } from '@/motion/Morph';
import { Splash } from '@/motion/Splash';
import { usePushBridge } from '@/notify/push';
import { usePrefs } from '@/state/prefs';
import { useSession } from '@/state/session';
import { useTheme } from '@/theme/theme';
import { Toasts } from '@/ui/Toast';

void SplashScreen.preventAutoHideAsync();

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 60_000,
      gcTime: 10 * 60_000,
      // Don't hammer the server on "not found" or "not allowed"; do retry a flaky connection.
      retry: (count, err) => count < 2 && !(err instanceof ApiError && err.status >= 400 && err.status < 500),
    },
  },
});

export default function RootLayout() {
  const [fontsLoaded] = useFonts({ Inter_400Regular, Inter_500Medium, Inter_600SemiBold, Inter_700Bold });
  const hydrate = useSession((s) => s.hydrate);
  const sessionReady = useSession((s) => s.ready);
  const [prefsReady, setPrefsReady] = useState(usePrefs.persist.hasHydrated());

  useEffect(() => {
    void hydrate();
    return usePrefs.persist.onFinishHydration(() => setPrefsReady(true));
  }, [hydrate]);

  const ready = fontsLoaded && sessionReady && prefsReady;

  return (
    <GestureHandlerRootView style={{ flex: 1 }}>
      <SafeAreaProvider>
        <QueryClientProvider client={queryClient}>
          {ready && <Root />}
          <MorphOverlay />
          <FlightOverlay />
          <ForwardCutOverlay />
          <Toasts />
          <Splash ready={ready} />
        </QueryClientProvider>
      </SafeAreaProvider>
    </GestureHandlerRootView>
  );
}

function Root() {
  const { c, dark } = useTheme();
  usePushBridge();

  useEffect(() => {
    void SystemUI.setBackgroundColorAsync(c.bg);
  }, [c.bg]);

  const navTheme = {
    ...(dark ? DarkTheme : DefaultTheme),
    colors: { ...(dark ? DarkTheme : DefaultTheme).colors, background: c.bg, card: c.bg, text: c.fg, border: c.line, primary: c.accent },
  };

  return (
    <ThemeProvider value={navTheme}>
      <StatusBar style={dark ? 'light' : 'dark'} />
      <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: c.bg }, animation: 'slide_from_right', gestureEnabled: true }}>
        <Stack.Screen name="(tabs)" />
        <Stack.Screen name="product/[slug]" options={{ animation: 'fade_from_bottom' }} />
        <Stack.Screen name="welcome" options={{ animation: 'fade', gestureEnabled: false }} />
        <Stack.Screen name="garage" options={{ presentation: 'modal', animation: 'slide_from_bottom' }} />
        <Stack.Screen name="sign-in" options={{ presentation: 'modal', animation: 'slide_from_bottom' }} />
      </Stack>
    </ThemeProvider>
  );
}
