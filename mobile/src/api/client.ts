import Constants from 'expo-constants';
import { Platform } from 'react-native';

import type { ApiErrorBody } from '@/api/types';
import { useGarage, useSession } from '@/state/session';

/**
 * Talks to the Panmotive shop API. Every request says which install it is (X-Device-Id), which
 * vehicle is in the garage (X-Vehicle-Id) and, when signed in, who the shopper is (Bearer token).
 */
export const API_URL: string =
  (process.env.EXPO_PUBLIC_API_URL as string | undefined) ??
  (Constants.expoConfig?.extra?.apiUrl as string | undefined) ??
  'https://panmotive.com/api/shop/v1';

export class ApiError extends Error {
  status: number;
  code?: string;
  errors: Record<string, string[]>;

  constructor(status: number, body: ApiErrorBody) {
    super(body.message || ApiError.fallback(status));
    this.status = status;
    this.code = body.code;
    this.errors = body.errors ?? {};
  }

  /** The first message for a form field, if the server flagged one. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0];
  }

  static fallback(status: number): string {
    if (status === 0) return 'No connection. Check your internet and try again.';
    if (status === 429) return 'Too many tries. Please wait a moment.';
    if (status >= 500) return 'Something went wrong on our side. Please try again.';
    return 'That didn\'t work. Please try again.';
  }
}

type Options = {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
  body?: unknown;
  query?: Record<string, string | number | boolean | null | undefined>;
  headers?: Record<string, string>;
  signal?: AbortSignal;
};

export async function api<T>(path: string, opts: Options = {}): Promise<T> {
  const { token, deviceId } = useSession.getState();
  const vehicle = useGarage.getState().vehicle;

  const qs = opts.query
    ? '?' +
      Object.entries(opts.query)
        .filter(([, v]) => v !== undefined && v !== null && v !== '')
        .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
        .join('&')
    : '';

  const isForm = typeof FormData !== 'undefined' && opts.body instanceof FormData;
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-App-Platform': Platform.OS,
    ...(isForm ? {} : opts.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
    ...(deviceId ? { 'X-Device-Id': deviceId } : {}),
    ...(vehicle ? { 'X-Vehicle-Id': String(vehicle.id) } : {}),
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...opts.headers,
  };

  let res: Response;
  try {
    res = await fetch(`${API_URL}${path}${qs.length > 1 ? qs : ''}`, {
      method: opts.method ?? 'GET',
      headers,
      body: opts.body === undefined ? undefined : isForm ? (opts.body as FormData) : JSON.stringify(opts.body),
      signal: opts.signal,
    });
  } catch (e) {
    if ((e as Error).name === 'AbortError') throw e;
    throw new ApiError(0, {});
  }

  const text = await res.text();
  let json: unknown = null;
  try {
    json = text ? JSON.parse(text) : null;
  } catch {
    json = null;
  }

  if (!res.ok) {
    const err = new ApiError(res.status, (json as ApiErrorBody) ?? {});
    // The token died (signed out elsewhere, password changed): drop it so the app falls back to guest.
    if (res.status === 401 && token) {
      void useSession.getState().signOut();
    }
    throw err;
  }

  return json as T;
}

export const get = <T>(path: string, query?: Options['query'], signal?: AbortSignal) => api<T>(path, { query, signal });
export const post = <T>(path: string, body?: unknown) => api<T>(path, { method: 'POST', body: body ?? {} });
export const patch = <T>(path: string, body?: unknown) => api<T>(path, { method: 'PATCH', body: body ?? {} });
export const put = <T>(path: string, body?: unknown) => api<T>(path, { method: 'PUT', body: body ?? {} });
export const del = <T>(path: string, body?: unknown) => api<T>(path, { method: 'DELETE', body: body ?? {} });
