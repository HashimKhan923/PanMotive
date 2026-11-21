import { router } from 'expo-router';
import { useState } from 'react';

import { ApiError, patch, put } from '@/api/client';
import type { User } from '@/api/types';
import { useSession } from '@/state/session';
import { Button } from '@/ui/Button';
import { Area, FormScreen } from '@/ui/FormScreen';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';

/** Name and phone, and changing the password (which signs out every other phone). */
export default function ProfileScreen() {
  const { user, setUser } = useSession();
  const [name, setName] = useState(user?.name ?? '');
  const [phone, setPhone] = useState(user?.phone ?? '');
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState<'profile' | 'password' | null>(null);

  if (!user) {
    router.replace('/sign-in');
    return null;
  }

  const saveProfile = async () => {
    setBusy('profile');
    try {
      const r = await patch<{ user: User }>('/me', { name, phone: phone || null });
      setUser(r.user);
      toast('Profile saved');
    } catch (e) {
      if (e instanceof ApiError) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
    } finally {
      setBusy(null);
    }
  };

  const savePassword = async () => {
    setBusy('password');
    setErrors({});
    try {
      await put('/me/password', { current_password: current, password: next });
      setCurrent('');
      setNext('');
      toast('Password changed. Other phones were signed out.');
    } catch (e) {
      if (e instanceof ApiError) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
    } finally {
      setBusy(null);
    }
  };

  return (
    <FormScreen title="Profile & password">
      <Text variant="h3">Your details</Text>
      <Area value={name} onChangeText={setName} placeholder="Full name" multiline={false} error={errors.name} />
      <Area value={phone} onChangeText={setPhone} placeholder="Phone" multiline={false} keyboardType="phone-pad" error={errors.phone} />
      <Text variant="small" tone="muted">
        Email: {user.email}
      </Text>
      <Button title="Save details" kind="outline" loading={busy === 'profile'} onPress={() => void saveProfile()} />

      <Text variant="h3" style={{ marginTop: 18 }}>
        Change password
      </Text>
      {user.linked.length ? (
        <Text variant="small" tone="muted">
          You sign in with {user.linked.map((l) => (l === 'apple' ? 'Apple' : 'Google')).join(' and ')}. To set a password, use "Forgot password" on the sign-in screen.
        </Text>
      ) : null}
      <Area value={current} onChangeText={setCurrent} placeholder="Current password" multiline={false} secureTextEntry error={errors.current_password} />
      <Area value={next} onChangeText={setNext} placeholder="New password" multiline={false} secureTextEntry error={errors.password} />
      <Button title="Change password" kind="outline" loading={busy === 'password'} disabled={!current || next.length < 8} onPress={() => void savePassword()} />
    </FormScreen>
  );
}
