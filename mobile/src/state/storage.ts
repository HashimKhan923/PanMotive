import AsyncStorage from '@react-native-async-storage/async-storage';
import { createJSONStorage } from 'zustand/middleware';

/** Plain (non-secret) app state on the phone: theme, garage, recently viewed, compare, search history. */
export const persistStorage = createJSONStorage(() => AsyncStorage);
