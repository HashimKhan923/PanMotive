import { router } from 'expo-router';
import { View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Button } from '@/ui/Button';
import { Empty } from '@/ui/Section';
import { useTheme } from '@/theme/theme';

/** Temporary screen while the real one is being built (Phase 2–4). */
export function Placeholder({ title, icon = 'sparkles' }: { title: string; icon?: string }) {
  const { c } = useTheme();
  const insets = useSafeAreaInsets();

  return (
    <View style={{ flex: 1, backgroundColor: c.bg, paddingTop: insets.top, justifyContent: 'center' }}>
      <Empty icon={icon} title={title} body="This screen is being built.">
        {router.canGoBack() ? <Button title="Back" kind="outline" onPress={() => router.back()} /> : null}
      </Empty>
    </View>
  );
}
