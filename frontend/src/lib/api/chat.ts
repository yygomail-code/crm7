import { apiRequest, apiUpload } from './client';
import type { ChatAttachment, ChatMessage, ChatThread } from './types';

export function listThreads(): Promise<{ items: ChatThread[]; unread: number }> {
  return apiRequest<{ items: ChatThread[]; unread: number }>('/chat/threads', { auth: true });
}

export function startThread(peerId: number): Promise<{ id: number }> {
  return apiRequest<{ id: number }>('/chat/threads', {
    method: 'POST',
    auth: true,
    body: { peer_id: peerId }
  });
}

export function listMessages(
  threadId: number,
  afterId = 0,
  requestId = 0
): Promise<{ items: ChatMessage[] }> {
  const params = new URLSearchParams();

  if (afterId > 0) params.set('after_id', String(afterId));
  if (requestId > 0) params.set('request_id', String(requestId));

  const suffix = params.toString() ? `?${params.toString()}` : '';

  return apiRequest<{ items: ChatMessage[] }>(`/chat/threads/${threadId}/messages${suffix}`, {
    auth: true
  });
}

export function sendMessage(
  threadId: number,
  body: string,
  attachmentIds: number[] = [],
  requestId = 0
): Promise<{ id: number; created_at: string }> {
  return apiRequest<{ id: number; created_at: string }>(`/chat/threads/${threadId}/messages`, {
    method: 'POST',
    auth: true,
    body: {
      body,
      attachment_ids: attachmentIds,
      ...(requestId > 0 ? { request_id: requestId } : {})
    }
  });
}

export function uploadChatAttachment(threadId: number, file: File): Promise<ChatAttachment> {
  const form = new FormData();
  form.append('file', file);

  return apiUpload<ChatAttachment>(`/chat/threads/${threadId}/attachments`, form);
}

export function markThreadRead(threadId: number, upTo = 0): Promise<{ read_up_to: number }> {
  return apiRequest<{ read_up_to: number }>(`/chat/threads/${threadId}/read`, {
    method: 'POST',
    auth: true,
    body: upTo > 0 ? { up_to: upTo } : {}
  });
}

export function chatUnread(): Promise<{ unread: number }> {
  return apiRequest<{ unread: number }>('/chat/unread', { auth: true });
}

export function quickReplies(): Promise<{ items: { id: number; body: string }[] }> {
  return apiRequest<{ items: { id: number; body: string }[] }>('/chat/quick-replies', { auth: true });
}
