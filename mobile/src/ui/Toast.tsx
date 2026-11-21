import { useEffect } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated, { FadeOutUp, LinearTransition, SlideInUp } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { create } from 'zustand';

import { Icon } from '@/ui/Icon';
import { haptic } from '@/ui/Press';
import { Text } from '@/ui/Text';
import { spring } from '@/theme/motion';
import { palette, radius } from '@/theme/tokens';

type Toast = { id: number; message: string; kind: 'success' | 'error' | 'info' };

const useToasts = create<{ list: Toast[]; push: (t: Omit<Toast, 'id'>) => void; drop: (id: number) => void }>((set, get) => ({
  list: [],
  push: (t) => set({ list: [...get().list.slice(-2), { ...t, id: Date.now() + Math.random() }] }),
  drop: (id) => set({ list: get().list.filter((t) => t.id !== id) }),
}));

/** toast('Added to cart'), toast('Sold out', 'error'). */
export const toast = (message: string, kind: Toast['kind'] = 'success') => {
  haptic(kind === 'error' ? 'error' : 'success');
  useToasts.getState().push({ message, kind });
};

/** Toasts drop in from the top on a spring and stack, like the website's. */
export function Toasts() {
  const list = useToasts((s) => s.list);
  const insets = useSafeAreaInsets();

  return (
    <View pointerEvents="box-none" style={[StyleSheet.absoluteFill, { paddingTop: insets.top + 8, alignItems: 'center', zIndex: 90 }]}>
      {list.map((t) => (
        <ToastItem key={t.id} toast={t} />
      ))}
    </View>
  );
}

function ToastItem({ toast: t }: { toast: Toast }) {
  const drop = useToasts((s) => s.drop);

  useEffect(() => {
    const timer = setTimeout(() => drop(t.id), 2800);
    return () => clearTimeout(timer);
  }, [drop, t.id]);

  const icon = t.kind === 'error' ? 'alert' : t.kind === 'info' ? 'info' : 'check';

  return (
    <Animated.View
      entering={SlideInUp.springify().damping(spring.snappy.damping).stiffness(spring.snappy.stiffness)}
      exiting={FadeOutUp.duration(220)}
      layout={LinearTransition.springify()}
      style={[styles.toast, { backgroundColor: palette.charcoal, borderColor: 'rgba(255,255,255,0.08)' }]}>
      <View style={[styles.dot, { backgroundColor: t.kind === 'error' ? palette.red : t.kind === 'info' ? '#3E4652' : '#18794E' }]}>
        <Icon name={icon} size={16} color="#fff" />
      </View>
      <Text variant="smallStrong" style={{ color: '#fff', flexShrink: 1 }}>
        {t.message}
      </Text>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  toast: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    paddingVertical: 10,
    paddingLeft: 10,
    paddingRight: 16,
    borderRadius: radius.pill,
    borderWidth: 1,
    marginBottom: 8,
    maxWidth: '92%',
    shadowColor: '#000',
    shadowOpacity: 0.35,
    shadowRadius: 18,
    shadowOffset: { width: 0, height: 8 },
    elevation: 10,
  },
  dot: { width: 26, height: 26, borderRadius: 13, alignItems: 'center', justifyContent: 'center' },
});
