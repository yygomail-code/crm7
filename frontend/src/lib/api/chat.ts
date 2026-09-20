import { apiRequest, apiUpload } from './client';
import type { ChatAttachment, ChatMessage, ChatThread } from './types';

export function listThreads(): Promise<{ items: ChatThread[]; unread: number }> {
  return apiRequest<{ items: ChatThread[]; unread: number }>('/chat/threads', { auth: true });
}

export function listMessages(threadId: number, afterId = 0): Promise<{ items: ChatMessage[] }> {
  const suffix = afterId > 0 ? `?after_id=${afterId}` : '';

  return apiRequest<{ items: ChatMessage[] }>(`/chat/threads/${threadId}/messages${suffix}`, {
    auth: true
  });
}

export function sendMessage(
  threadId: number,
  body: string,
  attachmentIds: number[] = []
): Promise<{ id: number; created_at: string }> {
  return apiRequest<{ id: number; created_at: string }>(`/chat/threads/${threadId}/messages`, {
    method: 'POST',
    auth: true,
    body: { body, attachment_ids: attachmentIds }
  });
}

export function uploadChatAttachment(threadId: number, file: File): Promise<ChatAttachment> {
  const form = new FormData();
  form.append('file', file);

  return apiUpload<ChatAttachment>(`/chat/threads/${threadId}/attachments`, form);
}

export function markThreadRead(threadId: number): Promise<{ read_up_to: number }> {
  return apiRequest<{ read_up_to: number }>(`/chat/threads/${threadId}/read`, {
    method: 'POST',
    auth: true,
    body: {}
  });
}

export function chatUnread(): Promise<{ unread: number }> {
  return apiRequest<{ unread: number }>('/chat/unread', { auth: true });
}

export function quickReplies(): Promise<{ items: { id: number; body: string }[] }> {
  return apiRequest<{ items: { id: number; body: string }[] }>('/chat/quick-replies', { auth: true });
}
