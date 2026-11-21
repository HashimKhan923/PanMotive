import { ActivityIndicator, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';

import { Icon, type IconName } from '@/ui/Icon';
import { Press } from '@/ui/Press';
import { Text } from '@/ui/Text';
import { useTheme } from '@/theme/theme';
import { radius } from '@/theme/tokens';

type Props = {
  title: string;
  onPress?: () => void;
  kind?: 'accent' | 'outline' | 'ghost' | 'light';
  size?: 'md' | 'lg' | 'sm';
  icon?: IconName;
  iconRight?: IconName;
  loading?: boolean;
  disabled?: boolean;
  block?: boolean;
  style?: StyleProp<ViewStyle>;
  accessibilityLabel?: string;
};

/** The website's buttons: red accent (with its soft red glow), outline, ghost, and white-on-red. */
export function Button({ title, onPress, kind = 'accent', size = 'md', icon, iconRight, loading, disabled, block, style, accessibilityLabel }: Props) {
  const { c } = useTheme();
  const h = size === 'lg' ? 54 : size === 'sm' ? 36 : 46;
  const fg = kind === 'accent' ? c.accentFg : kind === 'light' ? '#15191E' : c.fg;

  const look: ViewStyle =
    kind === 'accent'
      ? { backgroundColor: disabled ? c.surface2 : c.accent, shadowColor: c.accent, shadowOpacity: disabled ? 0 : 0.45, shadowRadius: 16, shadowOffset: { width: 0, height: 8 }, elevation: disabled ? 0 : 6 }
      : kind === 'light'
        ? { backgroundColor: '#FFFFFF' }
        : kind === 'outline'
          ? { borderWidth: 1, borderColor: c.line, backgroundColor: 'transparent' }
          : { backgroundColor: 'transparent' };

  return (
    <Press
      onPress={disabled || loading ? undefined : onPress}
      haptic={kind === 'accent' ? 'medium' : 'light'}
      accessibilityLabel={accessibilityLabel ?? title}
      accessibilityState={{ disabled: !!disabled, busy: !!loading }}
      style={[styles.base, { height: h, paddingHorizontal: size === 'sm' ? 14 : 20, opacity: disabled ? 0.6 : 1 }, look, block && { alignSelf: 'stretch' }, style]}>
      {loading ? (
        <ActivityIndicator color={fg} />
      ) : (
        <View style={styles.row}>
          {icon && <Icon name={icon} size={size === 'sm' ? 16 : 20} color={fg} />}
          <Text variant={size === 'sm' ? 'smallStrong' : 'bodyStrong'} style={{ color: fg }}>
            {title}
          </Text>
          {iconRight && <Icon name={iconRight} size={size === 'sm' ? 16 : 20} color={fg} />}
        </View>
      )}
    </Press>
  );
}

const styles = StyleSheet.create({
  base: { borderRadius: radius.md, alignItems: 'center', justifyContent: 'center' },
  row: { flexDirection: 'row', alignItems: 'center', gap: 8 },
});
