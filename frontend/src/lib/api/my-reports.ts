import { apiRequest, downloadFromApi } from './client';
import type { MyReportSummary } from './types';

export interface MyReportFilters {
  from?: string;
  to?: string;
  status?: string;
  priority?: string;
  warehouse_id?: string;
  item?: string;
  detail?: string;
}

function filterParams(filters: MyReportFilters): URLSearchParams {
  const params = new URLSearchParams();

  if (filters.from) params.set('from', filters.from);
  if (filters.to) params.set('to', filters.to);
  if (filters.status) params.set('status', filters.status);
  if (filters.priority) params.set('priority', filters.priority);
  if (filters.warehouse_id) params.set('warehouse_id', filters.warehouse_id);
  if (filters.item) params.set('item', filters.item);
  if (filters.detail) params.set('detail', filters.detail);

  return params;
}

export function myReportSummary(filters: MyReportFilters): Promise<MyReportSummary> {
  return apiRequest<MyReportSummary>(`/reports/my/summary?${filterParams(filters).toString()}`, {
    auth: true
  });
}

export function downloadMyReport(filters: MyReportFilters, format: string): Promise<void> {
  const params = filterParams(filters);
  params.set('format', format);

  return downloadFromApi(
    `/reports/my/export?${params.toString()}`,
    `Мой отчёт.${format}`
  );
}

export function emailMyReport(
  filters: MyReportFilters,
  format: string
): Promise<{ sent: boolean; email: string }> {
  const params = filterParams(filters);
  params.set('format', format);
  params.set('email', '1');

  return apiRequest<{ sent: boolean; email: string }>(`/reports/my/export?${params.toString()}`, {
    auth: true
  });
}
