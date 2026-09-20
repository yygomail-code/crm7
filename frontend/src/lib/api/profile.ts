import { config } from '../config';
import { auth } from '../stores/auth.svelte';
import { apiRequest, apiUpload } from './client';

export interface UserProfile {
  id: number;
  name: string;
  level: number;
  is_staff: boolean;
  position: string;
  company: string;
  email: string;
  phone: string;
  has_avatar: boolean;
}

export function updateProfile(name: string): Promise<{ id: number; name: string }> {
  return apiRequest<{ id: number; name: string }>('/profile', {
    method: 'PATCH',
    auth: true,
    body: { name }
  });
}

export function uploadAvatar(file: File): Promise<{ has_avatar: boolean }> {
  const form = new FormData();
  form.append('file', file);

  return apiUpload<{ has_avatar: boolean }>('/profile/avatar', form);
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
