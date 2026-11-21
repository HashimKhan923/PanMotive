import { router } from 'expo-router';
import { type ReactNode } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, TextInput, View, type TextInputProps } from 'react-native';
import Animated, { FadeInDown } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Icon } from '@/ui/Icon';
import { Press } from '@/ui/Press';
import { Text } from '@/ui/Text';
import { useTheme } from '@/theme/theme';
import { font, palette, radius } from '@/theme/tokens';

/** A simple screen with a title, a short explanation, a form and a sticky action at the bottom. */
export function FormScreen({ title, intro, children, footer }: { title: string; intro?: string; children: ReactNode; footer?: ReactNode }) {
  const { c } = useTheme();
  const insets = useSafeAreaInsets();

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: c.bg }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <View style={[styles.top, { paddingTop: insets.top + 4 }]}>
        <Press onPress={() => router.back()} style={styles.iconBtn} accessibilityLabel="Back">
          <Icon name="arrow-left" size={22} />
        </Press>
        <Text variant="h3" numberOfLines={1} style={{ flex: 1, textAlign: 'center' }}>
          {title}
        </Text>
        <View style={styles.iconBtn} />
      </View>
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: 16, paddingBottom: 120 + insets.bottom, gap: 12 }}>
        {intro ? (
          <Animated.View entering={FadeInDown.springify()}>
            <Text variant="body" tone="muted">
              {intro}
            </Text>
          </Animated.View>
        ) : null}
        <Animated.View entering={FadeInDown.delay(60).springify()} style={{ gap: 12 }}>
          {children}
        </Animated.View>
      </ScrollView>
      {footer ? <View style={[styles.footer, { paddingBottom: insets.bottom + 12, borderTopColor: c.line, backgroundColor: c.bg }]}>{footer}</View> : null}
    </KeyboardAvoidingView>
  );
}

export function Area({ error, ...rest }: TextInputProps & { error?: string }) {
  const { c } = useTheme();
  return (
    <View>
      <TextInput
        multiline
        placeholderTextColor={c.muted}
        {...rest}
        style={[styles.area, { color: c.fg, backgroundColor: c.surface, borderColor: error ? palette.red : c.line }, rest.multiline === false && { minHeight: 50 }]}
      />
      {error ? (
        <Text variant="small" tone="error" style={{ marginTop: 4 }}>
          {error}
        </Text>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: 8, paddingBottom: 4 },
  iconBtn: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  area: { minHeight: 120, borderWidth: 1, borderRadius: radius.md, padding: 14, fontFamily: font.regular, fontSize: 15, textAlignVertical: 'top' },
  footer: { borderTopWidth: StyleSheet.hairlineWidth, paddingHorizontal: 16, paddingTop: 12 },
});
