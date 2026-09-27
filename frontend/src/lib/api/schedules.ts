import { apiRequest } from './client';
import type { ReportSchedule } from './types';

export function listSchedules(): Promise<ReportSchedule[]> {
  return apiRequest<ReportSchedule[]>('/admin/report-schedules', { auth: true });
}

export interface SchedulePayload {
  title: string;
  report_type: string;
  frequency: string;
  time_of_day: string;
  day_of_week?: number | null;
  day_of_month?: number | null;
  recipients: number[];
  extra_emails: string[];
  is_active: boolean;
}

export function saveSchedule(id: number | null, payload: SchedulePayload): Promise<ReportSchedule[]> {
  const path = id === null ? '/admin/report-schedules' : `/admin/report-schedules/${id}`;

  return apiRequest<ReportSchedule[]>(path, { method: 'POST', auth: true, body: payload });
}

export function deleteSchedule(id: number): Promise<{ deleted: boolean }> {
  return apiRequest<{ deleted: boolean }>(`/admin/report-schedules/${id}`, {
    method: 'DELETE',
    auth: true
  });
}

export function sendScheduleNow(id: number): Promise<{ sent: number }> {
  return apiRequest<{ sent: number }>(`/admin/report-schedules/${id}/send`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}
