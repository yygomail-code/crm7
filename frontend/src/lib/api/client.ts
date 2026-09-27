import { config } from '../config';
import type { ApiResult, LegacyEnvelope } from './types';

export class ApiError extends Error {
  readonly code: string;

  readonly status: number;

  constructor(code: string, message?: string, status = 0) {
    super(message ?? code);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
  }
}

export interface AuthTokens {
  token: string | null;
  ut: string | null;
  us: string | null;
}

let tokenProvider: () => AuthTokens = () => ({ token: null, ut: null, us: null });
let unauthorizedHandler: (() => void) | null = null;

export function setTokenProvider(provider: () => AuthTokens): void {
  tokenProvider = provider;
}

export function setUnauthorizedHandler(handler: () => void): void {
  unauthorizedHandler = handler;
}

export function toBase64(value: string): string {
  const bytes = new TextEncoder().encode(value);
  let binary = '';
  for (const byte of bytes) {
    binary += String.fromCharCode(byte);
  }
  return btoa(binary);
}

export interface CallOptions {
  auth?: boolean;
  signal?: AbortSignal;
}

export async function callModule<T>(
  module: string,
  payload: Record<string, string | Blob> = {},
  options: CallOptions = {}
): Promise<ApiResult<T>> {
  const body = new FormData();
  body.append('module', module);

  for (const [key, value] of Object.entries(payload)) {
    body.append(key, value);
  }

  if (options.auth) {
    const tokens = tokenProvider();
    if (tokens.ut && tokens.us) {
      body.append('ut', tokens.ut);
      body.append('us', tokens.us);
    }
  }

  let response: Response;

  try {
    response = await fetch(config.apiBase, {
      method: 'POST',
      body,
      signal: options.signal
    });
  } catch (error) {
    throw new ApiError('network', error instanceof Error ? error.message : 'network error');
  }

  if (!response.ok) {
    throw new ApiError(`http_${response.status}`, `HTTP ${response.status}`, response.status);
  }

  let envelope: LegacyEnvelope<T>;

  try {
    envelope = (await response.json()) as LegacyEnvelope<T>;
  } catch {
    throw new ApiError('bad_json', 'Сервер вернул некорректный ответ');
  }

  const level = typeof envelope.level === 'number' ? envelope.level : 0;

  if (envelope.status === 'ok') {
    return { ok: true, level, data: (envelope.res ?? null) as T | null, error: null };
  }

  const error = envelope.error ?? 'unknown';

  if (error === '401') {
    unauthorizedHandler?.();
  }

  return { ok: false, level, data: null, error };
}

export interface ApiRequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
  body?: unknown;
  auth?: boolean;
  signal?: AbortSignal;
}

export async function apiUpload<T>(path: string, formData: FormData): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  const tokens = tokenProvider();

  if (tokens.token) {
    headers.Authorization = `Bearer ${tokens.token}`;
  }

  let response: Response;

  try {
    response = await fetch(`${config.apiV2Base}${path}`, {
      method: 'POST',
      headers,
      body: formData
    });
  } catch (error) {
    throw new ApiError('network', error instanceof Error ? error.message : 'network error');
  }

  let payload: unknown = null;

  try {
    payload = await response.json();
  } catch {
    payload = null;
  }

  if (response.status === 401) {
    unauthorizedHandler?.();
  }

  if (!response.ok) {
    const error = (payload as { error?: { code?: string; message?: string } } | null)?.error;
    throw new ApiError(
      error?.code ?? `http_${response.status}`,
      error?.message ?? `HTTP ${response.status}`,
      response.status
    );
  }

  return ((payload as { data?: T } | null)?.data ?? null) as T;
}

export async function downloadFromApi(path: string, fallbackName: string): Promise<void> {
  const { blob, fileName } = await apiDownload(path);
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');

  link.href = url;
  link.download = fileName || fallbackName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

export async function apiDownload(path: string): Promise<{ blob: Blob; fileName: string }> {
  const headers: Record<string, string> = {};
  const tokens = tokenProvider();

  if (tokens.token) {
    headers.Authorization = `Bearer ${tokens.token}`;
  }

  let response: Response;

  try {
    response = await fetch(`${config.apiV2Base}${path}`, { headers });
  } catch (error) {
    throw new ApiError('network', error instanceof Error ? error.message : 'network error');
  }

  if (response.status === 401) {
    unauthorizedHandler?.();
  }

  if (!response.ok) {
    let message = `HTTP ${response.status}`;

    try {
      const payload = (await response.json()) as { error?: { message?: string } } | null;
      message = payload?.error?.message ?? message;
    } catch {
      // ответ не JSON — оставляем статус
    }

    throw new ApiError('download_failed', message, response.status);
  }

  const disposition = response.headers.get('Content-Disposition') ?? '';
  const match = /filename\*=UTF-8''([^;]+)/i.exec(disposition);
  const fileName = match ? decodeURIComponent(match[1]) : 'file';

  return { blob: await response.blob(), fileName };
}

export async function apiRequest<T>(path: string, options: ApiRequestOptions = {}): Promise<T> {
  const method = options.method ?? 'GET';
  const headers: Record<string, string> = { Accept: 'application/json' };
  let body: string | undefined;

  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(options.body);
  }

  if (options.auth) {
    const tokens = tokenProvider();
    if (tokens.token) {
      headers.Authorization = `Bearer ${tokens.token}`;
    }
  }

  let response: Response;

  try {
    response = await fetch(`${config.apiV2Base}${path}`, {
      method,
      headers,
      body,
      signal: options.signal
    });
  } catch (error) {
    throw new ApiError('network', error instanceof Error ? error.message : 'network error');
  }

  let payload: unknown = null;

  try {
    payload = await response.json();
  } catch {
    payload = null;
  }

  if (response.status === 401) {
    unauthorizedHandler?.();
  }

  if (!response.ok) {
    const error = (payload as { error?: { code?: string; message?: string } } | null)?.error;
    throw new ApiError(
      error?.code ?? `http_${response.status}`,
      error?.message ?? `HTTP ${response.status}`,
      response.status
    );
  }

  return ((payload as { data?: T } | null)?.data ?? null) as T;
}
