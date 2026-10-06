import { apiRequest } from './client';
import type {
  AdminUser,
  AuditEntry,
  DatabaseSettings,
  EmailQueueItem,
  EmailSettings,
  EmailTemplate,
  ReportSchedule,
  RolesResponse,
  SystemSettings
} from './types';

export function getEmailSettings(): Promise<EmailSettings> {
  return apiRequest<EmailSettings>('/admin/settings/email', { auth: true });
}

export interface EmailSettingsPayload {
  enabled: boolean;
  host: string;
  port: number;
  encryption: string;
  username: string;
  password?: string;
  from: string;
  from_name: string;
}

export function saveEmailSettings(payload: EmailSettingsPayload): Promise<EmailSettings> {
  return apiRequest<EmailSettings>('/admin/settings/email', {
    method: 'POST',
    auth: true,
    body: payload
  });
}

export function testEmailConnection(): Promise<{ ok: boolean }> {
  return apiRequest<{ ok: boolean }>('/admin/settings/email/test', {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function sendTestEmail(to: string): Promise<{ sent: boolean }> {
  return apiRequest<{ sent: boolean }>('/admin/settings/email/test', {
    method: 'POST',
    auth: true,
    body: { to }
  });
}

export function listTemplates(): Promise<EmailTemplate[]> {
  return apiRequest<EmailTemplate[]>('/admin/templates', { auth: true });
}

export function saveTemplate(code: string, subject: string, body: string): Promise<EmailTemplate[]> {
  return apiRequest<EmailTemplate[]>(`/admin/templates/${encodeURIComponent(code)}`, {
    method: 'POST',
    auth: true,
    body: { subject, body }
  });
}

export function listQueue(): Promise<EmailQueueItem[]> {
  return apiRequest<EmailQueueItem[]>('/admin/queue', { auth: true });
}

export function getSystemSettings(): Promise<SystemSettings> {
  return apiRequest<SystemSettings>('/admin/settings/system', { auth: true });
}

export function saveSystemSettings(payload: {
  sla_reaction_hours: number;
  sla_resolution_hours: number;
  spf_checklist: boolean;
  sales_enabled: boolean;
  email_export_enabled: boolean;
  stock_reserve_enabled: boolean;
  stock_allow_zero: boolean;
  prices_enabled: boolean;
  groups_enabled: boolean;
  requests_enabled: boolean;
  cart_enabled: boolean;
  substitutions_enabled: boolean;
  manager_assign_enabled: boolean;
  app_title: string;
  client_label: string;
}): Promise<SystemSettings> {
  return apiRequest<SystemSettings>('/admin/settings/system', {
    method: 'POST',
    auth: true,
    body: payload
  });
}

export interface DatabaseSettingsPayload {
  host: string;
  port: number;
  database: string;
  user: string;
  password?: string;
}

export function getDatabaseSettings(): Promise<DatabaseSettings> {
  return apiRequest<DatabaseSettings>('/admin/settings/database', { auth: true });
}

export function testDatabaseSettings(
  payload: DatabaseSettingsPayload
): Promise<{ ok: boolean; server: string }> {
  return apiRequest<{ ok: boolean; server: string }>('/admin/settings/database/test', {
    method: 'POST',
    auth: true,
    body: payload
  });
}

export function saveDatabaseSettings(payload: DatabaseSettingsPayload): Promise<DatabaseSettings> {
  return apiRequest<DatabaseSettings>('/admin/settings/database', {
    method: 'POST',
    auth: true,
    body: payload
  });
}

export function listUsers(params: {
  q?: string;
  level?: number;
  state?: string;
  page?: number;
  per_page?: number;
}): Promise<{ items: AdminUser[]; total: number; page: number; per_page: number }> {
  const search = new URLSearchParams();

  if (params.q) search.set('q', params.q);
  if (params.level) search.set('level', String(params.level));
  if (params.state) search.set('state', params.state);
  if (params.page) search.set('page', String(params.page));
  if (params.per_page) search.set('per_page', String(params.per_page));

  const suffix = search.toString() !== '' ? `?${search.toString()}` : '';

  return apiRequest(`/users${suffix}`, { auth: true });
}

export interface AdminUserPayload {
  name: string;
  email: string;
  phone?: string;
  company?: string;
  inn?: string;
  position?: string;
  level: number;
  password?: string;
  price_type_id?: number | null;
}

export function createUser(payload: AdminUserPayload): Promise<{ id: number; login: string; password: string | null }> {
  return apiRequest<{ id: number; login: string; password: string | null }>('/users', {
    method: 'POST',
    auth: true,
    body: payload
  });
}

export function updateUser(
  id: number,
  payload: Partial<Omit<AdminUserPayload, 'email' | 'password'>>
): Promise<{ id: number; level: number }> {
  return apiRequest<{ id: number; level: number }>(`/users/${id}`, {
    method: 'PATCH',
    auth: true,
    body: payload
  });
}

export function blockUser(id: number): Promise<{ id: number; active: boolean }> {
  return apiRequest<{ id: number; active: boolean }>(`/users/${id}/block`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function unblockUser(id: number): Promise<{ id: number; active: boolean }> {
  return apiRequest<{ id: number; active: boolean }>(`/users/${id}/unblock`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function resetUserPassword(id: number): Promise<{ id: number; password: string }> {
  return apiRequest<{ id: number; password: string }>(`/users/${id}/reset-password`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function listRoles(): Promise<RolesResponse> {
  return apiRequest<RolesResponse>('/roles', { auth: true });
}

export function saveRole(level: number, capabilities: string[]): Promise<RolesResponse> {
  return apiRequest<RolesResponse>(`/roles/${level}`, {
    method: 'POST',
    auth: true,
    body: { capabilities }
  });
}

export function listAudit(params: {
  action?: string;
  entity?: string;
  user_id?: number;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
}): Promise<{ items: AuditEntry[]; total: number; page: number; per_page: number }> {
  const search = new URLSearchParams();

  if (params.action) search.set('action', params.action);
  if (params.entity) search.set('entity', params.entity);
  if (params.user_id) search.set('user_id', String(params.user_id));
  if (params.from) search.set('from', params.from);
  if (params.to) search.set('to', params.to);
  if (params.page) search.set('page', String(params.page));
  if (params.per_page) search.set('per_page', String(params.per_page));

  const suffix = search.toString() !== '' ? `?${search.toString()}` : '';

  return apiRequest(`/admin/audit${suffix}`, { auth: true });
}
