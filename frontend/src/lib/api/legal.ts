import { apiRequest } from './client';

export interface LegalDocument {
  code: string;
  title: string;
  body: string;
  updated_at: string;
}

export function getLegalDocument(code: string): Promise<LegalDocument> {
  return apiRequest<LegalDocument>(`/legal/${encodeURIComponent(code)}`);
}

export function listLegalDocuments(): Promise<{ items: LegalDocument[] }> {
  return apiRequest<{ items: LegalDocument[] }>('/admin/legal', { auth: true });
}

export function saveLegalDocument(code: string, title: string, body: string): Promise<{ items: LegalDocument[] }> {
  return apiRequest<{ items: LegalDocument[] }>(`/admin/legal/${encodeURIComponent(code)}`, {
    method: 'POST',
    auth: true,
    body: { title, body }
  });
}
