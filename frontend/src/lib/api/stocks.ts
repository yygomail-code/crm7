import { apiRequest, apiUpload, downloadFromApi } from './client';
import type { StockImportJob, StockItemPayload, StockLevel, StockUpdate, StockWarehouse } from './types';

export interface StockFilters {
  q?: string;
  qty_op?: string;
  qty?: string;
  sort?: string;
  show_zero?: boolean;
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
  warehouseId: number,
  filters: StockFilters,
  page = 1,
  perPage = 50
): Promise<{
  items: StockLevel[];
  total: number;
  page: number;
  per_page: number;
  can_edit: boolean;
  warehouse: { id: number; name: string };
}> {
  const params = filterParams(filters);
  params.set('warehouse_id', String(warehouseId));
  params.set('page', String(page));
  params.set('per_page', String(perPage));

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
  filters: StockFilters,
  format: string
): Promise<void> {
  const params = filterParams(filters);
  params.set('warehouse_id', '0');
  params.set('format', format);

  return downloadFromApi(`/stocks/export?${params.toString()}`, `Остатки по складам ${new Date().toISOString().slice(0, 10)}.${format}`);
}

export function emailLevels(
  filters: StockFilters,
  format: string
): Promise<{ sent: boolean; email: string }> {
  const params = filterParams(filters);
  params.set('warehouse_id', '0');
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
