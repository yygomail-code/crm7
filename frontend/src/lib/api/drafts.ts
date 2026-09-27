import { apiRequest } from './client';
import type { CreateRequestItem } from './requests';

export interface RequestDraftSummary {
  id: number;
  subject: string;
  priority: number;
  items_count: number;
  created_at: string;
  updated_at: string;
}

export interface RequestDraft {
  id: number;
  subject: string;
  body: string;
  priority: number;
  client_id: number;
  items: CreateRequestItem[];
  created_at: string;
  updated_at: string;
}

export interface RequestDraftPayload {
  subject: string;
  body: string;
  priority: number;
  client_id?: number;
  items: CreateRequestItem[];
}

export function listDrafts(): Promise<{ items: RequestDraftSummary[] }> {
  return apiRequest<{ items: RequestDraftSummary[] }>('/request-drafts', { auth: true });
}

export function getDraft(id: number): Promise<RequestDraft> {
  return apiRequest<RequestDraft>(`/request-drafts/${id}`, { auth: true });
}

export function createDraft(payload: RequestDraftPayload): Promise<RequestDraft> {
  return apiRequest<RequestDraft>('/request-drafts', { method: 'POST', auth: true, body: payload });
}

export function updateDraft(id: number, payload: RequestDraftPayload): Promise<RequestDraft> {
  return apiRequest<RequestDraft>(`/request-drafts/${id}`, { method: 'PATCH', auth: true, body: payload });
}

export function deleteDraft(id: number): Promise<{ deleted: boolean }> {
  return apiRequest<{ deleted: boolean }>(`/request-drafts/${id}`, { method: 'DELETE', auth: true });
}
