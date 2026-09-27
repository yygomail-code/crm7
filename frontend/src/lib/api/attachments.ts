import { apiRequest, apiUpload, downloadFromApi } from './client';
import type { RequestAttachment } from './types';

export interface AttachmentList {
  items: RequestAttachment[];
  can_upload: boolean;
  max_size: number;
  request_id: number;
}

export function listAttachments(requestId: number): Promise<AttachmentList> {
  return apiRequest<AttachmentList>(`/requests/${requestId}/attachments`, { auth: true });
}

export function uploadAttachment(requestId: number, file: File): Promise<AttachmentList> {
  const formData = new FormData();
  formData.append('file', file);

  return apiUpload<AttachmentList>(`/requests/${requestId}/attachments`, formData);
}

export function deleteAttachment(attachmentId: number): Promise<{ deleted: boolean }> {
  return apiRequest<{ deleted: boolean }>(`/attachments/${attachmentId}`, {
    method: 'DELETE',
    auth: true
  });
}

export function downloadAttachment(attachmentId: number, fallbackName: string): Promise<void> {
  return downloadFromApi(`/attachments/${attachmentId}`, fallbackName);
}
