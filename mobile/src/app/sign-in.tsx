import { useQueryClient } from '@tanstack/react-query';
import * as AppleAuthentication from 'expo-apple-authentication';
import * as Crypto from 'expo-crypto';
import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, TextInput, View } from 'react-native';
import Animated, { FadeIn, FadeInDown, LinearTransition } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError, post } from '@/api/client';
import type { User } from '@/api/types';
import { useSession } from '@/state/session';
import { Button } from '@/ui/Button';
import { Icon } from '@/ui/Icon';
import { Logo } from '@/ui/Logo';
import { haptic, Press } from '@/ui/Press';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { useTheme } from '@/theme/theme';
import { font, palette, radius } from '@/theme/tokens';

type Mode = 'login' | 'register' | 'forgot';
type Auth = { token: string; user: User };

/**
 * Sign in / create account / forgot password, and Sign in with Apple on iPhone. Whatever was in the
 * cart and saved list on this phone moves into the account.
 */
export default function SignInScreen() {
  const params = useLocalSearchParams<{ mode?: Mode }>();
  const { c, dark } = useTheme();
  const insets = useSafeAreaInsets();
  const qc = useQueryClient();
  const signIn = useSession((s) => s.signIn);
  const [mode, setMode] = useState<Mode>(params.mode === 'register' ? 'register' : 'login');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [show, setShow] = useState(false);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [appleOk, setAppleOk] = useState(false);

  useEffect(() => {
    if (Platform.OS === 'ios') void AppleAuthentication.isAvailableAsync().then(setAppleOk);
  }, []);

  const done = async (r: Auth, welcome: string) => {
    await signIn(r.token, r.user);
    haptic('success');
    toast(welcome);
    void qc.invalidateQueries();
    router.back();
  };

  const fail = (e: unknown) => {
    if (e instanceof ApiError) {
      setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
      if (!Object.keys(e.errors).length) toast(e.message, 'error');
    } else toast('Something went wrong. Please try again.', 'error');
    haptic('error');
  };

  const submit = async () => {
    setBusy(true);
    setErrors({});
    try {
      if (mode === 'forgot') {
        const r = await post<{ message: string }>('/auth/forgot-password', { email });
        toast(r.message, 'info');
        setMode('login');
      } else if (mode === 'register') {
        await done(await post<Auth>('/auth/register', { name, email: email.trim().toLowerCase(), password, device_name: deviceName() }), `Welcome to Panmotive, ${name.split(' ')[0]}!`);
      } else {
        const r = await post<Auth>('/auth/login', { email: email.trim(), password, device_name: deviceName() });
        await done(r, `Welcome back, ${r.user.name.split(' ')[0]}`);
      }
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  };

  const apple = async () => {
    try {
      const nonce = Crypto.randomUUID();
      const hashed = await Crypto.digestStringAsync(Crypto.CryptoDigestAlgorithm.SHA256, nonce);
      const cred = await AppleAuthentication.signInAsync({
        requestedScopes: [AppleAuthentication.AppleAuthenticationScope.FULL_NAME, AppleAuthentication.AppleAuthenticationScope.EMAIL],
        nonce: hashed,
      });
      if (!cred.identityToken) return;
      const fullName = [cred.fullName?.givenName, cred.fullName?.familyName].filter(Boolean).join(' ');
      const r = await post<Auth>('/auth/social/apple', { id_token: cred.identityToken, nonce, name: fullName || undefined, device_name: deviceName() });
      await done(r, `Welcome, ${r.user.name.split(' ')[0]}`);
    } catch (e) {
      if ((e as { code?: string }).code === 'ERR_REQUEST_CANCELED') return;
      fail(e);
    }
  };

  const tabs: Mode[] = ['login', 'register'];

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: c.bg }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: 24, paddingTop: insets.top + 12, paddingBottom: insets.bottom + 40 }}>
        <View style={styles.top}>
          <Logo height={22} />
          <Press onPress={() => router.back()} style={styles.close} accessibilityLabel="Close">
            <Icon name="x" size={22} />
          </Press>
        </View>

        <Animated.View entering={FadeInDown.springify()}>
          <Text variant="h1" style={{ marginTop: 28 }}>
            {mode === 'register' ? 'Create your account' : mode === 'forgot' ? 'Reset your password' : 'Welcome back'}
          </Text>
          <Text variant="body" tone="muted" style={{ marginTop: 6 }}>
            {mode === 'register'
              ? 'Track orders, message sellers and earn cashback on delivered orders.'
              : mode === 'forgot'
                ? 'We\'ll email you a link to choose a new password.'
                : 'Sign in to track orders, manage saved parts and spend your cashback.'}
          </Text>
        </Animated.View>

        {mode !== 'forgot' ? (
          <View style={[styles.tabs, { backgroundColor: c.surface2 }]}>
            <Animated.View style={[styles.tabPill, { backgroundColor: c.bg, left: mode === 'register' ? '50%' : 4 }]} layout={LinearTransition.springify().damping(18)} />
            {tabs.map((t) => (
              <Press key={t} haptic="selection" onPress={() => setMode(t)} style={styles.tab} accessibilityState={{ selected: mode === t }}>
                <Text variant="smallStrong" tone={mode === t ? 'fg' : 'muted'}>
                  {t === 'login' ? 'Sign in' : 'Create account'}
                </Text>
              </Press>
            ))}
          </View>
        ) : null}

        <Animated.View layout={LinearTransition.springify()} style={{ gap: 12, marginTop: 20 }}>
          {mode === 'register' ? (
            <Animated.View entering={FadeIn}>
              <Input icon="user" placeholder="Full name" value={name} onChangeText={setName} error={errors.name} textContentType="name" autoComplete="name" />
            </Animated.View>
          ) : null}
          <Input icon="mail" placeholder="Email" value={email} onChangeText={setEmail} error={errors.email} keyboardType="email-address" autoCapitalize="none" textContentType="emailAddress" autoComplete="email" />
          {mode !== 'forgot' ? (
            <Input
              icon="shield"
              placeholder="Password"
              value={password}
              onChangeText={setPassword}
              error={errors.password}
              secureTextEntry={!show}
              textContentType={mode === 'register' ? 'newPassword' : 'password'}
              autoComplete={mode === 'register' ? 'new-password' : 'current-password'}
              right={
                <Press onPress={() => setShow(!show)} haptic="selection" accessibilityLabel={show ? 'Hide password' : 'Show password'}>
                  <Text variant="smallStrong" tone="muted">
                    {show ? 'Hide' : 'Show'}
                  </Text>
                </Press>
              }
            />
          ) : null}
          {mode === 'login' ? (
            <Press onPress={() => setMode('forgot')} style={{ alignSelf: 'flex-end' }}>
              <Text variant="smallStrong" style={{ textDecorationLine: 'underline' }}>
                Forgot password?
              </Text>
            </Press>
          ) : null}
          <Button
            title={mode === 'register' ? 'Create account' : mode === 'forgot' ? 'Send reset link' : 'Sign in'}
            size="lg"
            loading={busy}
            onPress={() => void submit()}
            style={{ marginTop: 6 }}
          />
          {mode === 'forgot' ? <Button title="Back to sign in" kind="ghost" onPress={() => setMode('login')} /> : null}
        </Animated.View>

        {mode !== 'forgot' && appleOk ? (
          <Animated.View entering={FadeIn.delay(150)} style={{ marginTop: 22, gap: 12 }}>
            <View style={styles.or}>
              <View style={[styles.line, { backgroundColor: c.line }]} />
              <Text variant="small" tone="muted">
                or
              </Text>
              <View style={[styles.line, { backgroundColor: c.line }]} />
            </View>
            <AppleAuthentication.AppleAuthenticationButton
              buttonType={mode === 'register' ? AppleAuthentication.AppleAuthenticationButtonType.SIGN_UP : AppleAuthentication.AppleAuthenticationButtonType.SIGN_IN}
              buttonStyle={dark ? AppleAuthentication.AppleAuthenticationButtonStyle.WHITE : AppleAuthentication.AppleAuthenticationButtonStyle.BLACK}
              cornerRadius={radius.md}
              style={{ height: 52 }}
              onPress={() => void apple()}
            />
          </Animated.View>
        ) : null}

        <Text variant="small" tone="muted" center style={{ marginTop: 28, fontSize: 11 }}>
          By continuing you agree to Panmotive's Terms and Privacy Policy.
        </Text>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

function deviceName() {
  return `Panmotive ${Platform.OS === 'ios' ? 'iPhone' : Platform.OS === 'android' ? 'Android' : 'web'} app`;
}

function Input({ icon, error, right, ...rest }: React.ComponentProps<typeof TextInput> & { icon: string; error?: string; right?: React.ReactNode }) {
  const { c } = useTheme();
  const [focus, setFocus] = useState(false);

  return (
    <View>
      <View style={[styles.input, { backgroundColor: c.surface, borderColor: error ? palette.red : focus ? c.fg : c.line }]}>
        <Icon name={icon} size={18} color={c.muted} />
        <TextInput
          {...rest}
          onFocus={() => setFocus(true)}
          onBlur={() => setFocus(false)}
          placeholderTextColor={c.muted}
          style={{ flex: 1, color: c.fg, fontFamily: font.regular, fontSize: 16, height: '100%' }}
        />
        {right}
      </View>
      {error ? (
        <Animated.View entering={FadeIn}>
          <Text variant="small" tone="error" style={{ marginTop: 4 }}>
            {error}
          </Text>
        </Animated.View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  close: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  tabs: { flexDirection: 'row', borderRadius: radius.md, padding: 4, marginTop: 24 },
  tabPill: { position: 'absolute', top: 4, bottom: 4, width: '48%', borderRadius: radius.sm },
  tab: { flex: 1, height: 40, alignItems: 'center', justifyContent: 'center' },
  input: { flexDirection: 'row', alignItems: 'center', gap: 10, height: 54, borderWidth: 1.5, borderRadius: radius.md, paddingHorizontal: 14 },
  or: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  line: { flex: 1, height: StyleSheet.hairlineWidth },
});
