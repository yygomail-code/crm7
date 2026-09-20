<script lang="ts">
  import { onDestroy, onMount, tick } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    listMessages,
    listThreads,
    markThreadRead,
    quickReplies,
    sendMessage,
    uploadChatAttachment
  } from '../lib/api/chat';
  import { downloadFromApi } from '../lib/api/client';
  import type { ChatMessage, ChatThread } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { formatDateTime, formatRelative, formatSize } from '../lib/format';

  let threads = $state<ChatThread[]>([]);
  let messages = $state<ChatMessage[]>([]);
  let replies = $state<{ id: number; body: string }[]>([]);
  let activeId = $state<number | null>(null);
  let body = $state('');
  let loading = $state(true);
  let sending = $state(false);
  let error = $state('');
  let showList = $state(true);
  let scroller: HTMLDivElement | null = $state(null);
  let pendingFiles = $state<File[]>([]);
  let fileInput: HTMLInputElement | null = $state(null);

  const isStaff = $derived(auth.level >= 10);
  const active = $derived(threads.find((thread) => thread.id === activeId) ?? null);

  let timers: ReturnType<typeof setInterval>[] = [];

  onMount(() => {
    void init();

    timers.push(setInterval(() => void pollActive(), 5000));
    timers.push(setInterval(() => void pollThreads(), 15000));

    return () => {
      timers.forEach((timer) => clearInterval(timer));
      timers = [];
    };
  });

  onDestroy(() => {
    timers.forEach((timer) => clearInterval(timer));
  });

  async function init(): Promise<void> {
    loading = true;
    error = '';

    try {
      const data = await listThreads();
      threads = data.items;

      if (threads.length > 0 && !isStaff) {
        await open(threads[0].id);
      }

      if (isStaff) {
        replies = (await quickReplies()).items;
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить чат';
    } finally {
      loading = false;
    }
  }

  async function open(id: number): Promise<void> {
    activeId = id;
    showList = false;
    error = '';

    try {
      messages = (await listMessages(id)).items;
      await markThreadRead(id);
      threads = threads.map((thread) => (thread.id === id ? { ...thread, unread: 0 } : thread));
      await scrollDown();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось открыть диалог';
    }
  }

  async function pollActive(): Promise<void> {
    if (activeId === null || document.hidden) {
      return;
    }

    const lastId = messages.length > 0 ? messages[messages.length - 1].id : 0;

    try {
      const fresh = (await listMessages(activeId, lastId)).items;

      if (fresh.length > 0) {
        messages = [...messages, ...fresh];
        await markThreadRead(activeId);
        await scrollDown();
      }
    } catch {
      // некритично: повторим на следующем тике
    }
  }

  async function pollThreads(): Promise<void> {
    if (document.hidden) {
      return;
    }

    try {
      const data = await listThreads();
      threads = data.items.map((thread) =>
        thread.id === activeId ? { ...thread, unread: 0 } : thread
      );
    } catch {
      // некритично
    }
  }

  async function send(): Promise<void> {
    const text = body.trim();

    if ((text === '' && pendingFiles.length === 0) || activeId === null) {
      return;
    }

    sending = true;
    error = '';

    try {
      const attachmentIds: number[] = [];

      for (const file of pendingFiles) {
        const uploaded = await uploadChatAttachment(activeId, file);
        attachmentIds.push(uploaded.id);
      }

      await sendMessage(activeId, text, attachmentIds);
      body = '';
      pendingFiles = [];

      if (fileInput) {
        fileInput.value = '';
      }

      const lastId = messages.length > 0 ? messages[messages.length - 1].id : 0;
      messages = [...messages, ...(await listMessages(activeId, lastId)).items];
      await scrollDown();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось отправить сообщение';
    } finally {
      sending = false;
    }
  }

  function onFilesChange(event: Event): void {
    const input = event.target as HTMLInputElement;

    if (input.files) {
      pendingFiles = [...pendingFiles, ...Array.from(input.files)];
    }
  }

  function removePending(index: number): void {
    pendingFiles = pendingFiles.filter((_, i) => i !== index);

    if (fileInput) {
      fileInput.value = '';
    }
  }

  async function downloadAttachment(id: number, name: string): Promise<void> {
    try {
      await downloadFromApi(`/chat/attachments/${id}`, name);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось скачать файл';
    }
  }

  async function scrollDown(): Promise<void> {
    await tick();

    if (scroller) {
      scroller.scrollTop = scroller.scrollHeight;
    }
  }

  function useReply(text: string): void {
    body = text;
  }

  function threadTitle(thread: ChatThread): string {
    return isStaff ? thread.client.name : `Менеджер: ${thread.manager?.name ?? 'не назначен'}`;
  }
</script>

<section class="page" class:conversation={!showList && activeId !== null}>
  <div class="list-pane" class:hidden={!showList && activeId !== null}>
    <div class="head">
      <h1>Чат</h1>
      <Button variant="ghost" loading={loading} onclick={() => void pollThreads()}>Обновить</Button>
    </div>

    {#if error && activeId === null}
      <div class="alert">{error}</div>
    {/if}

    {#if loading}
      <div class="center"><Spinner size={26} /></div>
    {:else if threads.length === 0}
      <div class="empty">Диалогов нет</div>
    {:else}
      <div class="threads">
        {#each threads as thread (thread.id)}
          <button
            type="button"
            class="thread"
            class:active={thread.id === activeId}
            onclick={() => void open(thread.id)}
          >
            <div class="row">
              <span class="name">{threadTitle(thread)}</span>
              {#if thread.unread > 0}<span class="badge">{thread.unread}</span>{/if}
            </div>
            {#if thread.last_message}
              <div class="preview">
                {thread.last_message.user.name.split(' ')[0]}: {thread.last_message.body}
              </div>
            {/if}
            <div class="when" title={formatDateTime(thread.last_message_at)}>
              {formatRelative(thread.last_message_at)}
            </div>
          </button>
        {/each}
      </div>
    {/if}
  </div>

  <div class="chat-pane" class:hidden={showList || activeId === null}>
    {#if activeId === null}
      <div class="placeholder">Выберите диалог</div>
    {:else}
      <div class="chat-head">
        <button type="button" class="back" onclick={() => (showList = true)} aria-label="К списку">←</button>
        <div class="who">
          <div class="name">{active ? threadTitle(active) : ''}</div>
          {#if isStaff && active?.client}
            <div class="sub">{active.client.name}</div>
          {/if}
        </div>
      </div>

      <div class="messages" bind:this={scroller}>
        {#each messages as message (message.id)}
          <div class="message" class:mine={message.is_mine}>
            {#if !message.is_mine}
              <div class="author">
                {#if message.user.level >= 10}
                  <a class="author-link" href={`#/users/${message.user.id}`}>{message.user.name}</a>
                {:else}
                  {message.user.name}
                {/if}
              </div>
            {/if}
            {#if message.body}
              <div class="bubble">{message.body}</div>
            {/if}
            {#if message.attachments.length > 0}
              <div class="attachments">
                {#each message.attachments as attachment (attachment.id)}
                  <button
                    type="button"
                    class="attachment"
                    onclick={() => void downloadAttachment(attachment.id, attachment.name)}
                  >
                    <span class="clip" aria-hidden="true">⇩</span>
                    <span class="file-name">{attachment.name}</span>
                    <span class="file-size">{formatSize(attachment.size)}</span>
                  </button>
                {/each}
              </div>
            {/if}
            <div class="time" title={formatDateTime(message.created_at)}>
              {formatRelative(message.created_at)}
            </div>
          </div>
        {/each}
      </div>

      {#if isStaff && replies.length > 0}
        <div class="replies">
          {#each replies as reply (reply.id)}
            <button type="button" class="chip" onclick={() => useReply(reply.body)}>{reply.body}</button>
          {/each}
        </div>
      {/if}

      {#if error}
        <div class="alert">{error}</div>
      {/if}

      {#if pendingFiles.length > 0}
        <div class="pending">
          {#each pendingFiles as file, index (file.name + index)}
            <span class="pending-file">
              {file.name} · {formatSize(file.size)}
              <button type="button" onclick={() => removePending(index)} aria-label="Убрать">×</button>
            </span>
          {/each}
        </div>
      {/if}

      <form
        class="composer"
        onsubmit={(event) => {
          event.preventDefault();
          void send();
        }}
      >
        <label class="attach" title="Прикрепить файл (до 10 МБ)">
          <input
            bind:this={fileInput}
            type="file"
            multiple
            accept=".jpg,.jpeg,.png,.webp,.pdf,.xls,.xlsx,.doc,.docx,.txt,.zip,.rar"
            onchange={onFilesChange}
          />
          <span aria-hidden="true">⊕</span>
        </label>
        <textarea
          bind:value={body}
          placeholder="Введите сообщение…"
          rows="2"
          onkeydown={(event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
              event.preventDefault();
              void send();
            }
          }}
        ></textarea>
        <Button type="submit" loading={sending} disabled={body.trim() === '' && pendingFiles.length === 0}>
          Отправить
        </Button>
      </form>
    {/if}
  </div>
</section>

<style>
  .page {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: var(--space-4);
    height: calc(100vh - 160px);
  }

  .list-pane,
  .chat-pane {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    min-height: 0;
  }

  .head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
  }

  h1 {
    margin: 0;
    font-size: 22px;
  }

  .threads {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    overflow-y: auto;
  }

  .thread {
    display: flex;
    flex-direction: column;
    gap: 2px;
    text-align: left;
    padding: var(--space-3);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    cursor: pointer;
    font: inherit;
    color: inherit;
  }

  .thread.active {
    border-color: var(--primary);
  }

  .thread .row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .thread .name {
    font-weight: 500;
  }

  .badge {
    min-width: 20px;
    padding: 0 6px;
    border-radius: 999px;
    background: var(--primary);
    color: #fff;
    font-size: 12px;
    line-height: 18px;
    text-align: center;
  }

  .preview {
    font-size: 13px;
    color: var(--muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .when {
    font-size: 12px;
    color: var(--muted);
  }

  .chat-pane {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--space-3);
  }

  .chat-head {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding-bottom: var(--space-2);
    border-bottom: 1px solid var(--border);
  }

  .back {
    display: none;
    border: none;
    background: none;
    font-size: 18px;
    cursor: pointer;
    color: var(--muted);
  }

  .who .name {
    font-weight: 600;
  }

  .who .sub {
    font-size: 12px;
    color: var(--muted);
  }

  .messages {
    flex: 1;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding: var(--space-2) 0;
  }

  .message {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    max-width: 78%;
  }

  .message.mine {
    align-self: flex-end;
    align-items: flex-end;
  }

  .author {
    font-size: 12px;
    color: var(--muted);
  }

  .author-link {
    color: var(--muted);
    text-decoration: none;
    border-bottom: 1px dashed var(--border);
  }

  .author-link:hover {
    color: var(--primary);
    border-bottom-color: var(--primary);
  }

  .bubble {
    padding: 8px 12px;
    border-radius: 12px;
    background: color-mix(in srgb, var(--primary) 8%, white);
    border: 1px solid var(--border);
    white-space: pre-wrap;
    word-break: break-word;
    font-size: 14px;
  }

  .message.mine .bubble {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
  }

  .time {
    font-size: 11px;
    color: var(--muted);
  }

  .attachments {
    display: flex;
    flex-direction: column;
    gap: 4px;
    max-width: 100%;
  }

  .attachment {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface);
    font: inherit;
    font-size: 13px;
    color: inherit;
    cursor: pointer;
    text-align: left;
  }

  .attachment:hover {
    border-color: var(--primary);
  }

  .clip {
    color: var(--primary);
  }

  .file-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .file-size {
    font-size: 11px;
    color: var(--muted);
    white-space: nowrap;
  }

  .pending {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .pending-file {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border: 1px dashed var(--border);
    border-radius: 999px;
    font-size: 12px;
    color: var(--muted);
  }

  .pending-file button {
    border: none;
    background: none;
    color: var(--muted);
    cursor: pointer;
    font-size: 14px;
    line-height: 1;
    padding: 0;
  }

  .attach {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--muted);
    cursor: pointer;
    font-size: 18px;
  }

  .attach:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .attach input {
    display: none;
  }

  .replies {
    display: flex;
    gap: var(--space-2);
    overflow-x: auto;
    padding-bottom: 2px;
  }

  .chip {
    flex: 0 0 auto;
    max-width: 260px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    padding: 5px 12px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--muted);
    font: inherit;
    font-size: 12px;
    cursor: pointer;
  }

  .chip:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .composer {
    display: flex;
    gap: var(--space-2);
    align-items: flex-end;
  }

  .composer textarea {
    flex: 1;
    resize: none;
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font: inherit;
    font-size: 14px;
    background: var(--bg, #fff);
    color: var(--text);
  }

  .placeholder,
  .empty {
    color: var(--muted);
    text-align: center;
    padding: var(--space-6);
  }

  .placeholder {
    margin: auto;
  }

  .alert {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--danger);
    font-size: 13px;
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }

  @media (max-width: 720px) {
    .page {
      grid-template-columns: 1fr;
      height: auto;
      min-height: calc(100vh - 200px);
    }

    .list-pane.hidden,
    .chat-pane.hidden {
      display: none;
    }

    .chat-pane {
      min-height: 60vh;
    }

    .back {
      display: block;
    }

    .message {
      max-width: 88%;
    }
  }
</style>
