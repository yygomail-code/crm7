import { apiRequest } from './client';
import type { ItemGroup, ItemGroupItem } from './types';

export function listItemGroups(): Promise<{ items: ItemGroup[]; has_ungrouped: boolean }> {
  return apiRequest<{ items: ItemGroup[]; has_ungrouped: boolean }>('/item-groups', { auth: true });
}

export function listAdminItemGroups(): Promise<{ items: ItemGroup[] }> {
  return apiRequest<{ items: ItemGroup[] }>('/admin/item-groups', { auth: true });
}

export function listGroupItems(id: number): Promise<{ items: ItemGroupItem[] }> {
  return apiRequest<{ items: ItemGroupItem[] }>(`/admin/item-groups/${id}/items`, { auth: true });
}

export function createItemGroup(
  title: string,
  description = ''
): Promise<{ group: ItemGroup }> {
  return apiRequest<{ group: ItemGroup }>('/admin/item-groups', {
    method: 'POST',
    auth: true,
    body: { title, description }
  });
}

export function updateItemGroup(
  id: number,
  title: string,
  description = ''
): Promise<{ group: ItemGroup }> {
  return apiRequest<{ group: ItemGroup }>(`/admin/item-groups/${id}`, {
    method: 'PATCH',
    auth: true,
    body: { title, description }
  });
}

export function deleteItemGroup(id: number): Promise<{ id: number }> {
  return apiRequest<{ id: number }>(`/admin/item-groups/${id}`, {
    method: 'DELETE',
    auth: true
  });
}
