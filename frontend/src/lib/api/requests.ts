import { apiRequest } from './client';
import type {
  ActivityType,
  ClientItem,
  ManagerItem,
  RequestDetail,
  RequestListResponse,
  RequestStatus,
  RequestSummary
} from './types';

export function listStatuses(): Promise<RequestStatus[]> {
  return apiRequest<RequestStatus[]>('/request-statuses', { auth: true });
}

export function listActivityTypes(): Promise<ActivityType[]> {
  return apiRequest<ActivityType[]>('/activity-types', { auth: true });
}

export function addActivity(
  id: number,
  type: string,
  body: string,
  occurredAt?: string
): Promise<RequestDetail> {
  return apiRequest<RequestDetail>(`/requests/${id}/activities`, {
    method: 'POST',
    auth: true,
    body: { type, body, occurred_at: occurredAt ?? '' }
  });
}

export interface RequestFilters {
  status?: string;
  manager_scope?: string;
  q?: string;
  warehouse_id?: number;
  client_id?: number;
  state?: string;
  from?: string;
  to?: string;
  sort?: string;
  page?: number;
  per_page?: number;
}

export function requestFilters(): Promise<{
  statuses: RequestStatus[];
  warehouses: { id: number; name: string }[];
  sorts: { code: string; title: string }[];
}> {
  return apiRequest<{
    statuses: RequestStatus[];
    warehouses: { id: number; name: string }[];
    sorts: { code: string; title: string }[];
  }>('/requests/filters', { auth: true });
}

export function listRequests(filters: RequestFilters = {}): Promise<RequestListResponse> {
  const query = new URLSearchParams();

  if (filters.status) query.set('status', filters.status);
  if (filters.manager_scope) query.set('manager_scope', filters.manager_scope);
  if (filters.q) query.set('q', filters.q);
  if (filters.warehouse_id) query.set('warehouse_id', String(filters.warehouse_id));
  if (filters.client_id) query.set('client_id', String(filters.client_id));
  if (filters.state) query.set('state', filters.state);
  if (filters.from) query.set('from', filters.from);
  if (filters.to) query.set('to', filters.to);
  if (filters.sort) query.set('sort', filters.sort);
  if (filters.page && filters.page > 1) query.set('page', String(filters.page));
  if (filters.per_page) query.set('per_page', String(filters.per_page));

  const suffix = query.toString() ? `?${query.toString()}` : '';

  return apiRequest<RequestListResponse>(`/requests${suffix}`, { auth: true });
}

export function requestSummary(): Promise<RequestSummary> {
  return apiRequest<RequestSummary>('/requests/summary', { auth: true });
}

export function getRequest(id: number): Promise<RequestDetail> {
  return apiRequest<RequestDetail>(`/requests/${id}`, { auth: true });
}

export interface CreateRequestItem {
  warehouse_id: number | null;
  warehouse_name: string;
  stock_level_id?: number | null;
  name: string;
  unit: string;
  description?: string;
  quantity: number;
}

export interface CreateRequestPayload {
  subject: string;
  body?: string;
  priority?: number;
  client_id?: number;
  items?: CreateRequestItem[];
}

export function createRequest(payload: CreateRequestPayload): Promise<RequestDetail> {
  return apiRequest<RequestDetail>('/requests', { method: 'POST', auth: true, body: payload });
}

export interface UpdateRequestPayload {
  subject: string;
  body: string;
  items: CreateRequestItem[];
  priority?: number;
}

export function updateRequest(
  id: number,
  payload: UpdateRequestPayload,
  version: number
): Promise<RequestDetail> {
  return apiRequest<RequestDetail>(`/requests/${id}/edit`, {
    method: 'POST',
    auth: true,
    body: { ...payload, version }
  });
}

export function transitionRequest(
  id: number,
  toStatus: string,
  comment: string,
  version?: number
): Promise<RequestDetail> {
  return apiRequest<RequestDetail>(`/requests/${id}/transition`, {
    method: 'POST',
    auth: true,
    body: { to_status: toStatus, comment, version }
  });
}

export function updateRequestMeta(
  id: number,
  payload: { priority?: number; due_at?: string | null; comment?: string; subject?: string; body?: string },
  version: number
): Promise<RequestDetail> {
  return apiRequest<RequestDetail>(`/requests/${id}/meta`, {
    method: 'POST',
    auth: true,
    body: { ...payload, version }
  });
}

export function updateRequestItems(
  id: number,
  items: CreateRequestItem[],
  version: number,
  comment = ''
): Promise<RequestDetail> {
  return apiRequest<RequestDetail>(`/requests/${id}/items`, {
    method: 'POST',
    auth: true,
    body: { items, version, comment }
  });
}

export function claimRequest(id: number): Promise<RequestDetail> {
  return apiRequest<RequestDetail>(`/requests/${id}/claim`, { method: 'POST', auth: true });
}

export function assignRequest(
  id: number,
  managerId: number | null,
  comment: string,
  version?: number
): Promise<RequestDetail> {
  return apiRequest<RequestDetail>(`/requests/${id}/assign`, {
    method: 'POST',
    auth: true,
    body: { manager_id: managerId, comment, version }
  });
}

export function addComment(id: number, body: string, isInternal = true): Promise<RequestDetail> {
  return apiRequest<RequestDetail>(`/requests/${id}/comments`, {
    method: 'POST',
    auth: true,
    body: { body, is_internal: isInternal }
  });
}

export interface ClientListFilters {
  state?: string;
  manager?: string;
  from?: string;
  to?: string;
  sort?: string;
}

export function listClients(
  query = '',
  page = 1,
  perPage = 20,
  filters: ClientListFilters = {}
): Promise<{ items: ClientItem[]; total: number; page: number; per_page: number }> {
  const params = new URLSearchParams();

  if (query) params.set('q', query);
  if (page > 1) params.set('page', String(page));
  params.set('per_page', String(perPage));
  if (filters.state) params.set('state', filters.state);
  if (filters.manager) params.set('manager', filters.manager);
  if (filters.from) params.set('from', filters.from);
  if (filters.to) params.set('to', filters.to);
  if (filters.sort) params.set('sort', filters.sort);

  return apiRequest<{ items: ClientItem[]; total: number; page: number; per_page: number }>(
    `/clients?${params.toString()}`,
    { auth: true }
  );
}

export interface AssignClientResult {
  client_id: number;
  manager: { id: number; name: string };
}

export function assignClient(clientId: number, managerId: number): Promise<AssignClientResult> {
  return apiRequest<AssignClientResult>(`/clients/${clientId}/assign`, {
    method: 'POST',
    auth: true,
    body: { manager_id: managerId }
  });
}

export function listManagers(): Promise<{ items: ManagerItem[] }> {
  return apiRequest<{ items: ManagerItem[] }>('/managers', { auth: true });
}




