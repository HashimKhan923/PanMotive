import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Image } from 'expo-image';
import * as ImagePicker from 'expo-image-picker';
import { router, useLocalSearchParams } from 'expo-router';
import { useRef, useState } from 'react';
import { FlatList, KeyboardAvoidingView, Platform, StyleSheet, TextInput, View } from 'react-native';
import Animated, { FadeIn, FadeInUp, ZoomIn } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError, api, get } from '@/api/client';
import { Icon } from '@/ui/Icon';
import { haptic, Press } from '@/ui/Press';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { useTheme } from '@/theme/theme';
import { font, palette, radius } from '@/theme/tokens';

type Msg = { id: number; mine: boolean; body: string; photos: string[]; at: string; read: boolean };
type Convo = {
  id: number;
  seller: { name: string; slug: string; logo: string | null; reply_time: string | null } | null;
  product: { title: string; slug: string } | null;
  order: string | null;
  blocked: boolean;
  messages: Msg[];
};

/** A conversation with a seller: chat bubbles, photos, and it checks for new replies every few seconds. */
export default function ThreadScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { c } = useTheme();
  const insets = useSafeAreaInsets();
  const qc = useQueryClient();
  const [text, setText] = useState('');
  const [photos, setPhotos] = useState<ImagePicker.ImagePickerAsset[]>([]);
  const list = useRef<FlatList<Msg>>(null);

  const q = useQuery({
    queryKey: ['thread', id],
    queryFn: () => get<{ conversation: Convo }>(`/messages/${id}`).then((r) => r.conversation),
    refetchInterval: 8000,
  });
  const t = q.data;

  const send = useMutation({
    mutationFn: async () => {
      const form = new FormData();
      if (text.trim()) form.append('body', text.trim());
      photos.forEach((p, i) =>
        form.append('photos[]', { uri: p.uri, name: p.fileName ?? `photo-${i}.jpg`, type: p.mimeType ?? 'image/jpeg' } as unknown as Blob),
      );
      return api(`/messages/${id}`, { method: 'POST', body: form });
    },
    onSuccess: () => {
      haptic('light');
      setText('');
      setPhotos([]);
      void qc.invalidateQueries({ queryKey: ['thread', id] });
      void qc.invalidateQueries({ queryKey: ['messages'] });
    },
    onError: (e) => toast(e instanceof ApiError ? e.message : 'Message not sent. Try again.', 'error'),
  });

  const pick = async () => {
    const r = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], allowsMultipleSelection: true, selectionLimit: 4, quality: 0.7 });
    if (!r.canceled) setPhotos(r.assets.slice(0, 4));
  };

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: c.bg }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <View style={[styles.top, { paddingTop: insets.top + 4, borderBottomColor: c.line }]}>
        <Press onPress={() => router.back()} style={styles.iconBtn} accessibilityLabel="Back">
          <Icon name="arrow-left" size={22} />
        </Press>
        <Press haptic={false} onPress={() => t?.seller && router.push({ pathname: '/store/[slug]', params: { slug: t.seller.slug } })} style={{ flex: 1, flexDirection: 'row', alignItems: 'center', gap: 10 }}>
          {t?.seller?.logo ? (
            <Image source={t.seller.logo} style={styles.avatar} contentFit="contain" />
          ) : (
            <View style={[styles.avatar, { backgroundColor: palette.charcoal, alignItems: 'center', justifyContent: 'center' }]}>
              <Text variant="smallStrong" style={{ color: '#fff' }}>
                {t?.seller?.name.charAt(0) ?? ''}
              </Text>
            </View>
          )}
          <View style={{ flex: 1 }}>
            <Text variant="bodyStrong" numberOfLines={1}>
              {t?.seller?.name ?? ' '}
            </Text>
            <Text variant="small" tone="muted" numberOfLines={1} style={{ fontSize: 11 }}>
              {t?.seller?.reply_time ?? (t?.order ? `Order ${t.order}` : t?.product?.title ?? '')}
            </Text>
          </View>
        </Press>
      </View>

      {t?.product ? (
        <Press onPress={() => router.push({ pathname: '/product/[slug]', params: { slug: t.product!.slug } })} style={[styles.context, { backgroundColor: c.surface2 }]}>
          <Icon name="box" size={16} />
          <Text variant="small" numberOfLines={1} style={{ flex: 1 }}>
            About: {t.product.title}
          </Text>
          <Icon name="chevron-right" size={16} color={c.muted} />
        </Press>
      ) : null}

      <FlatList
        ref={list}
        data={t?.messages ?? []}
        keyExtractor={(m) => String(m.id)}
        contentContainerStyle={{ padding: 16, gap: 8 }}
        onContentSizeChange={() => list.current?.scrollToEnd({ animated: true })}
        renderItem={({ item: m }) => (
          <Animated.View entering={m.mine ? ZoomIn.springify().damping(16) : FadeInUp.springify()} style={{ alignItems: m.mine ? 'flex-end' : 'flex-start' }}>
            <View style={[styles.bubble, m.mine ? { backgroundColor: palette.red, borderBottomRightRadius: 6 } : { backgroundColor: c.surface2, borderBottomLeftRadius: 6 }]}>
              {m.photos.map((p) => (
                <Image key={p} source={p} style={styles.photo} contentFit="cover" />
              ))}
              {m.body ? (
                <Text variant="body" style={{ color: m.mine ? '#fff' : c.fg }}>
                  {m.body}
                </Text>
              ) : null}
            </View>
            <Text variant="small" tone="muted" style={{ fontSize: 10.5, marginTop: 2 }}>
              {new Date(m.at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}
              {m.mine && m.read ? ' · Read' : ''}
            </Text>
          </Animated.View>
        )}
      />

      {t?.blocked ? (
        <Text variant="small" tone="muted" center style={{ padding: 16 }}>
          This seller isn't accepting messages from your account.
        </Text>
      ) : (
        <View style={[styles.composer, { paddingBottom: insets.bottom + 8, borderTopColor: c.line, backgroundColor: c.bg }]}>
          {photos.length ? (
            <Animated.View entering={FadeIn} style={{ flexDirection: 'row', gap: 6, marginBottom: 8 }}>
              {photos.map((p, i) => (
                <Press key={p.uri} onPress={() => setPhotos(photos.filter((_, j) => j !== i))} accessibilityLabel="Remove photo">
                  <Image source={p.uri} style={styles.preview} contentFit="cover" />
                </Press>
              ))}
            </Animated.View>
          ) : null}
          <View style={{ flexDirection: 'row', alignItems: 'flex-end', gap: 8 }}>
            <Press onPress={() => void pick()} style={[styles.circle, { backgroundColor: c.surface2 }]} accessibilityLabel="Attach photos">
              <Icon name="image" size={20} />
            </Press>
            <TextInput
              value={text}
              onChangeText={setText}
              placeholder="Write a message"
              placeholderTextColor={c.muted}
              multiline
              style={[styles.input, { color: c.fg, backgroundColor: c.surface, borderColor: c.line }]}
            />
            <Press
              onPress={() => (text.trim() || photos.length) && !send.isPending && send.mutate()}
              style={[styles.circle, { backgroundColor: text.trim() || photos.length ? palette.red : c.surface2 }]}
              accessibilityLabel="Send">
              <Icon name="arrow-right" size={20} color={text.trim() || photos.length ? '#fff' : c.muted} />
            </Press>
          </View>
        </View>
      )}
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', gap: 4, paddingHorizontal: 8, paddingBottom: 8, borderBottomWidth: StyleSheet.hairlineWidth },
  iconBtn: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  avatar: { width: 38, height: 38, borderRadius: 11, overflow: 'hidden' },
  context: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 16, paddingVertical: 10 },
  bubble: { maxWidth: '82%', borderRadius: 18, paddingHorizontal: 14, paddingVertical: 10, gap: 6 },
  photo: { width: 200, height: 150, borderRadius: 12 },
  composer: { borderTopWidth: StyleSheet.hairlineWidth, paddingHorizontal: 12, paddingTop: 8 },
  input: { flex: 1, minHeight: 44, maxHeight: 120, borderWidth: 1, borderRadius: 22, paddingHorizontal: 16, paddingTop: 11, paddingBottom: 11, fontFamily: font.regular, fontSize: 15 },
  circle: { width: 44, height: 44, borderRadius: 22, alignItems: 'center', justifyContent: 'center' },
  preview: { width: 52, height: 52, borderRadius: radius.sm },
});
