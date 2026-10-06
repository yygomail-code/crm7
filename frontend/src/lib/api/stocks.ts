import { config } from '../config';
import { auth } from '../stores/auth.svelte';
import { apiRequest, apiUpload, downloadFromApi } from './client';
import type {
  StockImportJob,
  StockItemPayload,
  StockLevel,
  StockPhoto,
  StockUpdate,
  StockWarehouse
} from './types';

export interface StockFilters {
  q?: string;
  qty_op?: string;
  qty?: string;
  sort?: string;
  show_zero?: boolean;
  group_id?: number;
  group_ids?: number[];
  no_group?: boolean;
}

function filterParams(filters: StockFilters): URLSearchParams {
  const params = new URLSearchParams();

  if (filters.q) params.set('q', filters.q);
  if (filters.qty_op && filters.qty) {
    params.set('qty_op', filters.qty_op);
    params.set('qty', filters.qty);
  }
  if (filters.sort) params.set('sort', filters.sort);
  if (filters.show_zero) params.set('show_zero', '1');
  if (filters.group_id && filters.group_id > 0) params.set('group_id', String(filters.group_id));
  if (filters.group_ids && filters.group_ids.length > 0) params.set('group_ids', filters.group_ids.join(','));
  if (filters.no_group) params.set('no_group', '1');

  return params;
}

export function listWarehouses(): Promise<{
  items: StockWarehouse[];
  can_import: boolean;
  can_edit: boolean;
}> {
  return apiRequest<{ items: StockWarehouse[]; can_import: boolean; can_edit: boolean }>(
    '/stocks/warehouses',
    { auth: true }
  );
}

export function listLevels(
  warehouseIds: number[],
  filters: StockFilters,
  page = 1,
  perPage = 50,
  clientId = 0
): Promise<{
  items: StockLevel[];
  total: number;
  page: number;
  per_page: number;
  can_edit: boolean;
  prices?: { enabled: boolean; type: { id: number; title: string } | null };
  groups?: { enabled: boolean };
  warehouse: { id: number; name: string };
  warehouses?: { id: number; name: string }[];
}> {
  const params = filterParams(filters);
  params.set('warehouse_ids', warehouseIds.join(','));
  params.set('page', String(page));
  params.set('per_page', String(perPage));

  if (clientId > 0) {
    params.set('client_id', String(clientId));
  }

  return apiRequest(`/stocks/levels?${params.toString()}`, { auth: true });
}

export function renameWarehouse(
  warehouseId: number,
  name: string
): Promise<{ warehouse: { id: number; name: string } }> {
  return apiRequest<{ warehouse: { id: number; name: string } }>(`/stocks/${warehouseId}`, {
    method: 'PATCH',
    auth: true,
    body: { name }
  });
}

export function createStockItem(
  warehouseId: number,
  payload: StockItemPayload
): Promise<{ item: StockLevel }> {
  return apiRequest<{ item: StockLevel }>(`/stocks/${warehouseId}/items`, {
    method: 'POST',
    auth: true,
    body: payload
  });
}

export function updateStockItem(
  itemId: number,
  payload: StockItemPayload
): Promise<{ item: StockLevel }> {
  return apiRequest<{ item: StockLevel }>(`/stocks/levels/${itemId}`, {
    method: 'PATCH',
    auth: true,
    body: payload
  });
}

export function searchCounts(filters: StockFilters): Promise<{ counts: Record<string, number> }> {
  return apiRequest<{ counts: Record<string, number> }>(`/stocks/search-counts?${filterParams(filters).toString()}`, {
    auth: true
  });
}

export function exportLevels(
  warehouseIds: number[],
  filters: StockFilters,
  format: string
): Promise<void> {
  const params = filterParams(filters);
  params.set('warehouse_ids', warehouseIds.join(','));
  params.set('format', format);

  return downloadFromApi(`/stocks/export?${params.toString()}`, `Остатки ${new Date().toISOString().slice(0, 10)}.${format}`);
}

export function emailLevels(
  warehouseIds: number[],
  filters: StockFilters,
  format: string
): Promise<{ sent: boolean; email: string }> {
  const params = filterParams(filters);
  params.set('warehouse_ids', warehouseIds.join(','));
  params.set('format', format);
  params.set('email', '1');

  return apiRequest<{ sent: boolean; email: string }>(`/stocks/export?${params.toString()}`, {
    auth: true
  });
}

export function importLevels(file: File, actualDate: string): Promise<{
  job_id: number;
  file_name: string;
  actual_date: string;
  rows_total: number;
  rows_imported: number;
  rows_created: number;
  rows_zeroed: number;
  rows_skipped: number;
  warehouses_created: number;
  errors: string[];
}> {
  const form = new FormData();
  form.append('file', file);
  form.append('actual_date', actualDate);

  return apiUpload('/stocks/import', form);
}

export function importHistory(): Promise<{ jobs: StockImportJob[]; updates: StockUpdate[] }> {
  return apiRequest<{ jobs: StockImportJob[]; updates: StockUpdate[] }>('/stocks/import/history', {
    auth: true
  });
}

export function uploadItemPhoto(levelId: number, file: File): Promise<{ photos: StockPhoto[] }> {
  const form = new FormData();
  form.append('file', file);

  return apiUpload<{ photos: StockPhoto[] }>(`/stocks/levels/${levelId}/photos`, form);
}

export function deleteItemPhoto(id: number): Promise<{ photos: StockPhoto[] }> {
  return apiRequest<{ photos: StockPhoto[] }>(`/stocks/photos/${id}`, { method: 'DELETE', auth: true });
}

const photoUrlCache = new Map<number, string | null>();

export async function loadItemPhotoUrl(id: number): Promise<string | null> {
  if (photoUrlCache.has(id)) {
    return photoUrlCache.get(id) ?? null;
  }

  try {
    const response = await fetch(`${config.apiV2Base}/stocks/photos/${id}`, {
      headers: auth.token ? { Authorization: `Bearer ${auth.token}` } : {}
    });

    if (!response.ok) {
      photoUrlCache.set(id, null);

      return null;
    }

    const url = URL.createObjectURL(await response.blob());
    photoUrlCache.set(id, url);

    return url;
  } catch {
    photoUrlCache.set(id, null);

    return null;
  }
}

export function invalidateItemPhoto(id: number): void {
  const url = photoUrlCache.get(id);

  if (url) {
    URL.revokeObjectURL(url);
  }

  photoUrlCache.delete(id);
}
