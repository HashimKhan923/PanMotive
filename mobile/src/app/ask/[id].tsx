import { useQueryClient } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { ApiError, post } from '@/api/client';
import { useSession } from '@/state/session';
import { Button } from '@/ui/Button';
import { Area, FormScreen } from '@/ui/FormScreen';
import { Press } from '@/ui/Press';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { useTheme } from '@/theme/theme';
import { palette, radius } from '@/theme/tokens';

/** Ask about a part: publicly (Q&A on the product page) or privately (a message to the seller). */
export default function AskScreen() {
  const { id, title, mode: initial } = useLocalSearchParams<{ id: string; title?: string; mode?: 'question' | 'message' }>();
  const { c } = useTheme();
  const qc = useQueryClient();
  const signedIn = !!useSession((s) => s.token);
  const [mode, setMode] = useState<'question' | 'message'>(initial === 'question' ? 'question' : 'message');
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string>();

  if (!signedIn) {
    return (
      <FormScreen title="Ask the seller" intro="Sign in to ask the seller a question or send them a message.">
        <Button title="Sign in" onPress={() => router.replace('/sign-in')} />
      </FormScreen>
    );
  }

  const send = async () => {
    setBusy(true);
    setError(undefined);
    try {
      if (mode === 'question') {
        await post(`/products/${id}/questions`, { body });
        void qc.invalidateQueries({ queryKey: ['questions', Number(id)] });
        toast('Question posted — we\'ll let you know when the seller answers.');
        router.back();
      } else {
        const r = await post<{ conversation_id: number }>(`/products/${id}/message-seller`, { body });
        router.replace({ pathname: '/messages/[id]', params: { id: String(r.conversation_id) } });
      }
    } catch (e) {
      setError(e instanceof ApiError ? e.field('body') ?? e.message : 'That didn\'t send. Try again.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <FormScreen
      title="Ask the seller"
      intro={title ? `About: ${title}` : undefined}
      footer={<Button title={mode === 'question' ? 'Post question' : 'Send message'} size="lg" block loading={busy} disabled={body.trim().length < (mode === 'question' ? 8 : 2)} onPress={() => void send()} />}>
      <View style={{ flexDirection: 'row', gap: 8 }}>
        {(['message', 'question'] as const).map((m) => (
          <Press key={m} onPress={() => setMode(m)} haptic="selection" style={{ flex: 1, borderWidth: 1.5, borderColor: mode === m ? palette.red : c.line, borderRadius: radius.md, padding: 12 }}>
            <Text variant="smallStrong">{m === 'message' ? 'Private message' : 'Public question'}</Text>
            <Text variant="small" tone="muted" style={{ fontSize: 12 }}>
              {m === 'message' ? 'Only you and the seller see it.' : 'Shown on the part page to help others.'}
            </Text>
          </Press>
        ))}
      </View>
      <Area value={body} onChangeText={setBody} placeholder={mode === 'question' ? 'e.g. Does this fit the hatchback model?' : 'Write your message'} error={error} maxLength={mode === 'question' ? 500 : 4000} />
    </FormScreen>
  );
}
