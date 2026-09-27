import { apiRequest } from './client';
import type { Substitution } from './types';

export function listSubstitutions(): Promise<Substitution[]> {
  return apiRequest<Substitution[]>('/substitutions', { auth: true });
}

export interface SubstitutionPayload {
  manager_id: number;
  substitute_id: number;
  date_from: string;
  date_to: string;
  reason: string;
}

export function createSubstitution(payload: SubstitutionPayload): Promise<{ item: Substitution; moved: number }> {
  return apiRequest<{ item: Substitution; moved: number }>('/substitutions', {
    method: 'POST',
    auth: true,
    body: payload
  });
}

export function endSubstitution(id: number): Promise<{ ended?: boolean; deleted?: boolean; returned: number; item: Substitution | null }> {
  return apiRequest<{ ended?: boolean; deleted?: boolean; returned: number; item: Substitution | null }>(
    `/substitutions/${id}`,
    { method: 'DELETE', auth: true }
  );
}
