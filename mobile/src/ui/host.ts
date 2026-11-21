import type { ComponentRef } from 'react';
import type { View } from 'react-native';

/** A rendered view we can measure (React Native 0.8x refs are host elements, not class instances). */
export type HostView = ComponentRef<typeof View>;
