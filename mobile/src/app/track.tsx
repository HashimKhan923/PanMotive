import { router } from 'expo-router';
import { useState } from 'react';

import { ApiError, post } from '@/api/client';
import { useSession } from '@/state/session';
import { Button } from '@/ui/Button';
import { Area, FormScreen } from '@/ui/FormScreen';
import { Text } from '@/ui/Text';

/** Guests follow an order with its number and the email it was placed with. */
export default function TrackScreen() {
  const remember = useSession((s) => s.rememberGuestOrder);
  const [number, setNumber] = useState('');
  const [email, setEmail] = useState('');
  const [error, setError] = useState<string>();
  const [busy, setBusy] = useState(false);

  const go = async () => {
    setBusy(true);
    setError(undefined);
    try {
      const r = await post<{ number: string; access: string }>('/orders/track', { number: number.trim().toUpperCase(), email: email.trim() });
      remember(r.number, r.access);
      router.replace({ pathname: '/order/[number]', params: { number: r.number } });
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'We couldn\'t find that order.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <FormScreen
      title="Track an order"
      intro="Enter the order number from your receipt email and the email you used at checkout."
      footer={<Button title="Find my order" size="lg" block loading={busy} disabled={!number || !email} onPress={() => void go()} />}>
      <Area value={number} onChangeText={setNumber} placeholder="Order number, e.g. PM-AB12CD34EF" multiline={false} autoCapitalize="characters" />
      <Area value={email} onChangeText={setEmail} placeholder="Email" multiline={false} keyboardType="email-address" autoCapitalize="none" error={error} />
      <Text variant="small" tone="muted">
        Have an account? Your orders are under Account → Orders.
      </Text>
    </FormScreen>
  );
}
