import { config } from '../config';
import { auth } from '../stores/auth.svelte';
import { apiRequest, apiUpload } from './client';

export interface UserProfile {
  id: number;
  login: string;
  name: string;
  level: number;
  is_staff: boolean;
  position: string;
  company: string;
  email: string;
  phone: string;
  has_avatar: boolean;
}

export interface ProfileUpdatePayload {
  name: string;
  position?: string;
  phone?: string;
  email?: string;
}

export interface ProfileUpdateResult {
  id: number;
  name: string;
  position: string;
  phone: string;
  email: string;
}

export function updateProfile(payload: ProfileUpdatePayload): Promise<ProfileUpdateResult> {
  return apiRequest<ProfileUpdateResult>('/profile', {
    method: 'PATCH',
    auth: true,
    body: payload
  });
}

export function uploadAvatar(file: File): Promise<{ has_avatar: boolean }> {
  const form = new FormData();
  form.append('file', file);

  return apiUpload<{ has_avatar: boolean }>('/profile/avatar', form);
}

export function deleteAvatar(): Promise<{ has_avatar: boolean }> {
  return apiRequest<{ has_avatar: boolean }>('/profile/avatar', {
    method: 'DELETE',
    auth: true
  });
}

export function getUserProfile(id: number): Promise<UserProfile> {
  return apiRequest<UserProfile>(`/users/${id}/profile`, { auth: true });
}

const avatarCache = new Map<number, string | null>();

export async function loadAvatarUrl(userId: number): Promise<string | null> {
  if (avatarCache.has(userId)) {
    return avatarCache.get(userId) ?? null;
  }

  try {
    const response = await fetch(`${config.apiV2Base}/users/${userId}/avatar`, {
      headers: authHeader()
    });

    if (!response.ok) {
      avatarCache.set(userId, null);

      return null;
    }

    const url = URL.createObjectURL(await response.blob());
    avatarCache.set(userId, url);

    return url;
  } catch {
    avatarCache.set(userId, null);

    return null;
  }
}

export function invalidateAvatar(userId: number): void {
  avatarCache.delete(userId);
}

function authHeader(): Record<string, string> {
  return auth.token ? { Authorization: `Bearer ${auth.token}` } : {};
}
