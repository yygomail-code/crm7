import { apiRequest } from './client';
import type { ClientCard, PendingClient } from './types';

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
