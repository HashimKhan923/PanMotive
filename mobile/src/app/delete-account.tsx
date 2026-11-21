import { useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { ApiError, del } from '@/api/client';
import { useSession } from '@/state/session';
import { Button } from '@/ui/Button';
import { Area, FormScreen } from '@/ui/FormScreen';
import { Icon } from '@/ui/Icon';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { useTheme } from '@/theme/theme';
import { radius } from '@/theme/tokens';

/** Delete the account from inside the app (an App Store and Google Play requirement). */
export default function DeleteAccountScreen() {
  const { c } = useTheme();
  const qc = useQueryClient();
  const { user, signOut } = useSession();
  const social = !!user?.linked.length;
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState<string>();
  const [busy, setBusy] = useState(false);

  const go = async () => {
    setBusy(true);
    setError(undefined);
    try {
      await del('/me', social && !password ? { confirm } : { password });
      await signOut();
      qc.clear();
      toast('Your account has been deleted.', 'info');
      router.replace('/');
    } catch (e) {
      setError(e instanceof ApiError ? e.field('password') ?? e.field('confirm') ?? e.message : 'Couldn\'t delete the account.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <FormScreen
      title="Delete account"
      footer={<Button title="Delete my account" size="lg" block loading={busy} disabled={social ? confirm.trim().toUpperCase() !== 'DELETE' && !password : !password} onPress={() => void go()} />}>
      <View style={{ flexDirection: 'row', gap: 10, backgroundColor: c.errorBg, borderRadius: radius.md, padding: 14 }}>
        <Icon name="alert" size={20} color={c.error} />
        <Text variant="small" style={{ flex: 1, color: c.error }}>
          This closes your account and signs you out everywhere. Orders already placed still go through, and we keep the records the law requires (like receipts). Wallet credit is lost.
        </Text>
      </View>
      {social ? (
        <>
          <Text variant="small" tone="muted">
            Type DELETE to confirm.
          </Text>
          <Area value={confirm} onChangeText={setConfirm} placeholder="DELETE" multiline={false} autoCapitalize="characters" error={error} />
        </>
      ) : (
        <Area value={password} onChangeText={setPassword} placeholder="Your password" multiline={false} secureTextEntry error={error} />
      )}
    </FormScreen>
  );
}
