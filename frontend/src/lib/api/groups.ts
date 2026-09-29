import { apiRequest } from './client';
import type { ItemGroup } from './types';

export function listItemGroups(): Promise<{ items: ItemGroup[] }> {
  return apiRequest<{ items: ItemGroup[] }>('/item-groups', { auth: true });
}

export function listAdminItemGroups(): Promise<{ items: ItemGroup[] }> {
  return apiRequest<{ items: ItemGroup[] }>('/admin/item-groups', { auth: true });
}

export function createItemGroup(title: string): Promise<{ group: ItemGroup }> {
  return apiRequest<{ group: ItemGroup }>('/admin/item-groups', {
    method: 'POST',
    auth: true,
    body: { title }
  });
}

export function updateItemGroup(id: number, title: string): Promise<{ group: ItemGroup }> {
  return apiRequest<{ group: ItemGroup }>(`/admin/item-groups/${id}`, {
    method: 'PATCH',
    auth: true,
    body: { title }
  });
}

export function deleteItemGroup(id: number): Promise<{ id: number }> {
  return apiRequest<{ id: number }>(`/admin/item-groups/${id}`, {
    method: 'DELETE',
    auth: true
  });
}
