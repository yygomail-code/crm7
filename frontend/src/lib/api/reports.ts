import { apiRequest, downloadFromApi } from './client';
import type { ReportSummary, SalesLeadsReport } from './types';

export function getSalesLeads(from: string, to: string): Promise<SalesLeadsReport> {
  return apiRequest<SalesLeadsReport>(`/reports/sales-leads?from=${from}&to=${to}`, { auth: true });
}

export function downloadSalesLeads(from: string, to: string, format: string): Promise<void> {
  return downloadFromApi(
    `/reports/sales-leads/export?from=${from}&to=${to}&format=${format}`,
    `Целевые продажи.${format}`
  );
}

export function getReportSummary(from: string, to: string): Promise<ReportSummary> {
  return apiRequest<ReportSummary>(`/reports/summary?from=${from}&to=${to}`, { auth: true });
}

export function downloadReport(format: string, from: string, to: string): Promise<void> {
  return downloadFromApi(
    `/reports/export?format=${format}&from=${from}&to=${to}`,
    `Отчёт по заявкам.${format}`
  );
}

export function emailReport(
  format: string,
  from: string,
  to: string
): Promise<{ sent: boolean; email: string }> {
  return apiRequest<{ sent: boolean; email: string }>(
    `/reports/export?format=${format}&from=${from}&to=${to}&email=1`,
    { auth: true }
  );
}

export function emailSalesLeads(
  from: string,
  to: string,
  format: string
): Promise<{ sent: boolean; email: string }> {
  return apiRequest<{ sent: boolean; email: string }>(
    `/reports/sales-leads/export?from=${from}&to=${to}&format=${format}&email=1`,
    { auth: true }
  );
}
