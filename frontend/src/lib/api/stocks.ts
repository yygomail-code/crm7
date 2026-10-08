import { config } from '../config';
import { auth } from '../stores/auth.svelte';
import { apiRequest, apiUpload, downloadFromApi } from './client';
import type {
  ItemType,
  NomenclatureItem,
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
  active?: string;
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
  if (filters.active && filters.active !== 'all') params.set('active', filters.active);

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
  can_deactivate: boolean;
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

export function getStockLevel(itemId: number): Promise<{
  item: StockLevel;
  can_edit: boolean;
  can_manage_photos: boolean;
  can_deactivate: boolean;
  can_delete: boolean;
  deactivate_blocked: string | null;
  delete_blocked: string | null;
  prices_enabled: boolean;
  groups_enabled: boolean;
  item_types: ItemType[];
}> {
  return apiRequest(`/stocks/levels/${itemId}`, { auth: true });
}

export function activateStockItem(itemId: number): Promise<{ active: boolean }> {
  return apiRequest<{ active: boolean }>(`/stocks/levels/${itemId}/activate`, {
    method: 'POST',
    auth: true
  });
}

export function deactivateStockItem(itemId: number): Promise<{ active: boolean }> {
  return apiRequest<{ active: boolean }>(`/stocks/levels/${itemId}/deactivate`, {
    method: 'POST',
    auth: true
  });
}

export function deleteStockItem(itemId: number): Promise<{ deleted: boolean }> {
  return apiRequest<{ deleted: boolean }>(`/stocks/levels/${itemId}`, {
    method: 'DELETE',
    auth: true
  });
}

export function listItemTypes(): Promise<{ items: ItemType[] }> {
  return apiRequest<{ items: ItemType[] }>('/stocks/item-types', { auth: true });
}

export function searchNomenclature(query: string): Promise<{ items: NomenclatureItem[] }> {
  return apiRequest<{ items: NomenclatureItem[] }>(
    `/stocks/nomenclature?query=${encodeURIComponent(query)}`,
    { auth: true }
  );
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

export type WarehouseType = 'main' | 'transit' | 'returns' | 'reserve' | 'defect';

export interface WarehouseDirectoryItem {
  id: number;
  sid: string;
  name: string;
  address: string | null;
  active: boolean;
  type: WarehouseType;
  is_default: boolean;
  stock_num: number;
  sort: number;
  responsible_name: string | null;
  positions: number;
  positions_total: number;
  users: number;
  actual_date: string | null;
}

export interface WarehouseResponsible {
  sid: string;
  name: string;
  login: string;
}

export interface WarehouseCardData {
  id: number;
  sid: string;
  name: string;
  name_1c: string;
  address: string | null;
  contact_name: string | null;
  phone: string | null;
  email: string | null;
  note: string | null;
  type: WarehouseType;
  stock_num: number;
  sort: number;
  is_default: boolean;
  allow_orders: boolean;
  in_reports: boolean;
  responsible: WarehouseResponsible | null;
  active: boolean;
  levels: number[];
}

export interface WarehousePayload {
  name: string;
  name_1c: string;
  address: string;
  contact_name: string;
  phone: string;
  email: string;
  note: string;
  type: WarehouseType;
  stock_num: number;
  sort: number;
  levels: number[];
  active: boolean;
  is_default: boolean;
  allow_orders: boolean;
  in_reports: boolean;
  responsible_sid: string;
}

export interface WarehouseUser {
  id: number;
  user_sid: string;
  full_name: string;
  login: string;
  level: number;
  active: boolean;
}

export function listWarehouseDirectory(): Promise<{ items: WarehouseDirectoryItem[] }> {
  return apiRequest<{ items: WarehouseDirectoryItem[] }>('/stocks/warehouses?manage=1', { auth: true });
}

export function getWarehouse(id: number): Promise<{ warehouse: WarehouseCardData }> {
  return apiRequest<{ warehouse: WarehouseCardData }>(`/stocks/warehouses/${id}`, { auth: true });
}

export function updateWarehouse(
  id: number,
  data: WarehousePayload
): Promise<{ warehouse: WarehouseCardData }> {
  return apiRequest<{ warehouse: WarehouseCardData }>(`/stocks/warehouses/${id}`, {
    method: 'PATCH',
    auth: true,
    body: data
  });
}

export function listWarehouseUsers(id: number): Promise<{ users: WarehouseUser[] }> {
  return apiRequest<{ users: WarehouseUser[] }>(`/stocks/warehouses/${id}/access`, { auth: true });
}

export interface WarehouseCandidate {
  id: number;
  sid: string;
  login: string;
  name: string;
  level: number;
  email: string;
  has_access: boolean;
}

export function listWarehouseCandidates(
  id: number,
  params: { q: string; roles: number[]; sort: string }
): Promise<{ items: WarehouseCandidate[]; total: number }> {
  const search = new URLSearchParams();
  search.set('q', params.q);
  search.set('roles', params.roles.join(','));
  search.set('sort', params.sort);
  search.set('per_page', '100');

  return apiRequest<{ items: WarehouseCandidate[]; total: number }>(
    `/stocks/warehouses/${id}/candidates?${search.toString()}`,
    { auth: true }
  );
}

export function addWarehouseUser(id: number, userSid: string): Promise<{ users: WarehouseUser[] }> {
  return apiRequest<{ users: WarehouseUser[] }>(`/stocks/warehouses/${id}/access`, {
    method: 'POST',
    auth: true,
    body: { user_sid: userSid }
  });
}

export function removeWarehouseUser(id: number, userSid: string): Promise<{ users: WarehouseUser[] }> {
  return apiRequest<{ users: WarehouseUser[] }>(
    `/stocks/warehouses/${id}/access/${encodeURIComponent(userSid)}`,
    { method: 'DELETE', auth: true }
  );
}

export function createWarehouse(data: WarehousePayload): Promise<{ warehouse: WarehouseCardData }> {
  return apiRequest<{ warehouse: WarehouseCardData }>('/stocks/warehouses', {
    method: 'POST',
    auth: true,
    body: data
  });
}

export function exportWarehouses(format: string): Promise<void> {
  return downloadFromApi(
    `/stocks/warehouses?export=${encodeURIComponent(format)}`,
    `Склады ${new Date().toISOString().slice(0, 10)}.${format}`
  );
}

export function emailWarehouses(format: string): Promise<{ sent: boolean; email: string }> {
  return apiRequest<{ sent: boolean; email: string }>(
    `/stocks/warehouses?export=${encodeURIComponent(format)}&email=1`,
    { auth: true }
  );
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

export interface ImportPriceType {
  id: number;
  title: string;
}

export interface StockImportResult {
  status: 'done';
  job_id: number;
  file_name: string;
  actual_date: string;
  rows_total: number;
  rows_imported: number;
  rows_created: number;
  rows_zeroed: number;
  rows_skipped: number;
  warehouses_created: number;
  prices_imported: number;
  errors: string[];
}

export interface StockImportMapping {
  status: 'needs_mapping';
  columns: string[];
  types: ImportPriceType[];
}

export type StockImportResponse = StockImportResult | StockImportMapping;

export function importLevels(
  file: File,
  actualDate: string,
  mapping?: Record<string, number>
): Promise<StockImportResponse> {
  const form = new FormData();
  form.append('file', file);
  form.append('actual_date', actualDate);

  if (mapping !== undefined) {
    form.append('mapping', JSON.stringify(mapping));
  }

  return apiUpload<StockImportResponse>('/stocks/import', form);
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

const photoUrlCache = new Map<string, string | null>();

export type ItemPhotoSize = 'preview' | 'card' | 'max';

export async function loadItemPhotoUrl(id: number, size: ItemPhotoSize = 'card'): Promise<string | null> {
  const key = `${id}:${size}`;

  if (photoUrlCache.has(key)) {
    return photoUrlCache.get(key) ?? null;
  }

  try {
    const response = await fetch(`${config.apiV2Base}/stocks/photos/${id}?size=${size}`, {
      headers: auth.token ? { Authorization: `Bearer ${auth.token}` } : {}
    });

    if (!response.ok) {
      photoUrlCache.set(key, null);

      return null;
    }

    const url = URL.createObjectURL(await response.blob());
    photoUrlCache.set(key, url);

    return url;
  } catch {
    photoUrlCache.set(key, null);

    return null;
  }
}

export function invalidateItemPhoto(id: number): void {
  const prefix = `${id}:`;

  for (const [key, url] of photoUrlCache) {
    if (key.startsWith(prefix)) {
      if (url) {
        URL.revokeObjectURL(url);
      }

      photoUrlCache.delete(key);
    }
  }
}
