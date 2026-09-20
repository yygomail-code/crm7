import { apiRequest, apiUpload, downloadFromApi } from './client';
import type { StockImportJob, StockLevel, StockUpdate, StockWarehouse } from './types';

export interface StockFilters {
  q?: string;
  qty_op?: string;
  qty?: string;
  sort?: string;
}

function filterParams(filters: StockFilters): URLSearchParams {
  const params = new URLSearchParams();

  if (filters.q) params.set('q', filters.q);
  if (filters.qty_op && filters.qty) {
    params.set('qty_op', filters.qty_op);
    params.set('qty', filters.qty);
  }
  if (filters.sort) params.set('sort', filters.sort);

  return params;
}

export function listWarehouses(): Promise<{ items: StockWarehouse[]; can_import: boolean }> {
  return apiRequest<{ items: StockWarehouse[]; can_import: boolean }>('/stocks/warehouses', { auth: true });
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
  warehouse: { id: number; name: string };
}> {
  const params = filterParams(filters);
  params.set('warehouse_id', String(warehouseId));
  params.set('page', String(page));
  params.set('per_page', String(perPage));

  return apiRequest(`/stocks/levels?${params.toString()}`, { auth: true });
}

export function searchCounts(filters: StockFilters): Promise<{ counts: Record<string, number> }> {
  return apiRequest<{ counts: Record<string, number> }>(`/stocks/search-counts?${filterParams(filters).toString()}`, {
    auth: true
  });
}

export function exportLevels(
  warehouseId: number,
  filters: StockFilters,
  warehouseName: string,
  format: string
): Promise<void> {
  const params = filterParams(filters);
  params.set('warehouse_id', String(warehouseId));
  params.set('format', format);

  return downloadFromApi(`/stocks/export?${params.toString()}`, `Остатки ${warehouseName}.${format}`);
}

export function emailLevels(
  warehouseId: number,
  filters: StockFilters,
  format: string
): Promise<{ sent: boolean; email: string }> {
  const params = filterParams(filters);
  params.set('warehouse_id', String(warehouseId));
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
  rows_skipped: number;
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
