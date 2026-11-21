import { memo } from 'react';
import Svg, { Circle, Ellipse, Line, Path, Polygon, Polyline, Rect } from 'react-native-svg';

import { ICONS, type IconName } from '@/ui/icon-paths';
import { useTheme } from '@/theme/theme';

export type { IconName };

const TAGS = { path: Path, circle: Circle, rect: Rect, ellipse: Ellipse, line: Line, polyline: Polyline, polygon: Polygon } as const;

/** Map an SVG attribute name to its react-native-svg prop (stroke-width → strokeWidth). */
const camel = (k: string) => k.replace(/-([a-z])/g, (_, c: string) => c.toUpperCase());

type Props = { name: IconName | string; size?: 16 | 20 | 24 | 32 | 48 | number; color?: string; strokeWidth?: number; fill?: string };

/** The brand icon set: 24-grid line icons, stroke 2, round caps — identical to the website's x-ui.icon. */
export const Icon = memo(function Icon({ name, size = 24, color, strokeWidth = 2, fill = 'none' }: Props) {
  const { c } = useTheme();
  const els = (ICONS as Record<string, readonly (readonly [string, Record<string, string>])[]>)[name] ?? ICONS.box;

  return (
    <Svg width={size} height={size} viewBox="0 0 24 24" fill={fill} stroke={color ?? c.fg} strokeWidth={strokeWidth} strokeLinecap="round" strokeLinejoin="round">
      {els.map(([tag, attrs], i) => {
        const El = TAGS[tag as keyof typeof TAGS] as unknown as React.ComponentType<Record<string, unknown>>;
        const props = Object.fromEntries(Object.entries(attrs).map(([k, v]) => [camel(k), v]));
        return <El key={i} {...props} />;
      })}
    </Svg>
  );
});
