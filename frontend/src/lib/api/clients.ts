import { apiRequest } from './client';
import type { ClientCard, ClientInterest, PendingClient } from './types';

export interface RegisterPayload {
  name: string;
  company: string;
  inn: string;
  phone: string;
  email: string;
  password: string;
  password_confirm: string;
  consent: boolean;
}

export function registerClient(payload: RegisterPayload): Promise<{ pending: boolean; message: string }> {
  return apiRequest<{ pending: boolean; message: string }>('/auth/register', {
    method: 'POST',
    body: payload
  });
}

export function pendingClients(): Promise<{ items: PendingClient[] }> {
  return apiRequest<{ items: PendingClient[] }>('/clients/pending', { auth: true });
}

export function activateClient(id: number, comment: string): Promise<{ id: number; state: string }> {
  return apiRequest<{ id: number; state: string }>(`/clients/${id}/activate`, {
    method: 'POST',
    auth: true,
    body: { comment }
  });
}

export function rejectClient(id: number, comment: string): Promise<{ id: number; state: string }> {
  return apiRequest<{ id: number; state: string }>(`/clients/${id}/reject`, {
    method: 'POST',
    auth: true,
    body: { comment }
  });
}

export function getClientCard(id: number): Promise<ClientCard> {
  return apiRequest<ClientCard>(`/clients/${id}`, { auth: true });
}

export function getClientInterests(
  id: number,
  filters: { q?: string; sort?: string; page?: number; perPage?: number } = {}
): Promise<{ items: ClientInterest[]; total: number; page: number; per_page: number }> {
  const params = new URLSearchParams();

  if (filters.q) params.set('q', filters.q);
  if (filters.sort) params.set('sort', filters.sort);
  params.set('page', String(filters.page ?? 1));
  params.set('per_page', String(filters.perPage ?? 20));

  return apiRequest<{ items: ClientInterest[]; total: number; page: number; per_page: number }>(
    `/clients/${id}/interests?${params.toString()}`,
    { auth: true }
  );
}

export function blockClient(id: number, comment: string): Promise<{ id: number; active: boolean }> {
  return apiRequest<{ id: number; active: boolean }>(`/clients/${id}/block`, {
    method: 'POST',
    auth: true,
    body: { comment }
  });
}

export function unblockClient(id: number, comment: string): Promise<{ id: number; active: boolean }> {
  return apiRequest<{ id: number; active: boolean }>(`/clients/${id}/unblock`, {
    method: 'POST',
    auth: true,
    body: { comment }
  });
}

export interface ClientContactsPayload {
  name: string;
  company: string;
  inn: string;
  position: string;
  phone: string;
  email: string;
}

export function updateClientContacts(id: number, payload: ClientContactsPayload): Promise<{ id: number }> {
  return apiRequest<{ id: number }>(`/clients/${id}`, {
    method: 'PATCH',
    auth: true,
    body: payload
  });
}

export function claimClient(id: number): Promise<{ id: number }> {
  return apiRequest<{ id: number }>(`/clients/${id}/claim`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function assignClient(
  id: number,
  managerId: number | null,
  comment: string
): Promise<{ id: number }> {
  return apiRequest<{ id: number }>(`/clients/${id}/assign`, {
    method: 'POST',
    auth: true,
    body: { manager_id: managerId, comment }
  });
}

export function transferClient(
  id: number,
  toManagerId: number | null,
  dateFrom: string,
  dateTo: string | null,
  comment: string
): Promise<{ id: number }> {
  return apiRequest<{ id: number }>(`/clients/${id}/transfer`, {
    method: 'POST',
    auth: true,
    body: {
      to_manager_id: toManagerId,
      date_from: dateFrom,
      date_to: dateTo,
      comment
    }
  });
}

export function acceptTransfer(transferId: number): Promise<{ id: number }> {
  return apiRequest<{ id: number }>(`/client-transfers/${transferId}/accept`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function declineTransfer(transferId: number): Promise<{ id: number }> {
  return apiRequest<{ id: number }>(`/client-transfers/${transferId}/decline`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function cancelTransfer(transferId: number): Promise<{ id: number }> {
  return apiRequest<{ id: number }>(`/client-transfers/${transferId}/cancel`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}
