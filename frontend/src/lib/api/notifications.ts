import { apiRequest } from './client';
import type { NotificationItem } from './types';

export function listNotifications(
  unreadOnly = false,
  page = 1,
  perPage = 20
): Promise<{ items: NotificationItem[]; unread: number; total: number; page: number; per_page: number }> {
  const params = new URLSearchParams();

  if (unreadOnly) params.set('unread', '1');
  if (page > 1) params.set('page', String(page));
  params.set('per_page', String(perPage));

  return apiRequest<{ items: NotificationItem[]; unread: number; total: number; page: number; per_page: number }>(
    `/notifications?${params.toString()}`,
    { auth: true }
  );
}

export function unreadCount(): Promise<{ unread: number }> {
  return apiRequest<{ unread: number }>('/notifications/count', { auth: true });
}

export function markRead(payload: { ids?: number[]; all?: boolean }): Promise<{
  marked: number;
  unread: number;
}> {
  return apiRequest<{ marked: number; unread: number }>('/notifications/read', {
    method: 'POST',
    auth: true,
    body: payload
  });
}

export interface NotificationSettingItem {
  event: string;
  title: string;
  in_app: boolean;
  email: boolean;
}

export function getNotificationSettings(): Promise<{ items: NotificationSettingItem[] }> {
  return apiRequest<{ items: NotificationSettingItem[] }>('/notification-settings', { auth: true });
}

export function saveNotificationSettings(
  items: NotificationSettingItem[]
): Promise<{ items: NotificationSettingItem[] }> {
  return apiRequest<{ items: NotificationSettingItem[] }>('/notification-settings', {
    method: 'PUT',
    auth: true,
    body: { items }
  });
}
