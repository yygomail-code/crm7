<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { listNotifications, markRead } from '../lib/api/notifications';
  import type { NotificationItem } from '../lib/api/types';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Pagination from '../lib/components/ui/Pagination.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { formatDateTime, formatRelative } from '../lib/format';

  const typeIcons: Record<string, string> = {
    'request.new': '＋',
    'request.claim': '→',
    'request.status': '⇄',
    'request.comment': '✎',
    'request.assigned': '→',
    'request.transferred': '⇄',
    'request.assign': '→',
    'substitution.created': '⇄',
    'substitution.ended': '⇄',
    'user.pending': '☺',
    'user.approved': '☺',
    'user.rejected': '☺'
  };

  let items = $state<NotificationItem[]>([]);
  let unread = $state(0);
  let total = $state(0);
  let page = $state(1);
  const perPage = 20;
  let loading = $state(true);
  let error = $state('');
  let busy = $state(false);
  let filter = $state<'all' | 'unread'>('all');

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      const data = await listNotifications(filter === 'unread', page, perPage);
      items = data.items;
      unread = data.unread;
      total = data.total;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить уведомления';
    } finally {
      loading = false;
    }
  }

  function setFilter(value: 'all' | 'unread'): void {
    if (filter === value) {
      return;
    }

    filter = value;
    page = 1;
    void load();
  }

  function goToPage(next: number): void {
    page = next;
    void load();
  }

  async function markAll(): Promise<void> {
    busy = true;
    error = '';

    try {
      const data = await markRead({ all: true });
      unread = data.unread;

      if (filter === 'unread') {
        page = 1;
        await load();
      } else {
        items = items.map((item) => ({ ...item, is_read: true }));
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось обновить уведомления';
    } finally {
      busy = false;
    }
  }

  async function open(item: NotificationItem): Promise<void> {
    if (!item.is_read) {
      try {
        const data = await markRead({ ids: [item.id] });
        unread = data.unread;
        item.is_read = true;

        if (filter === 'unread') {
          items = items.filter((row) => row.id !== item.id);
          total = Math.max(0, total - 1);
        }
      } catch {
        // некритично
      }
    }

    if (item.request_id) {
      router.navigate(`/requests/${item.request_id}`);
    } else if (item.type === 'chat.message') {
      router.navigate('/chat');
    } else if (item.type === 'user.pending') {
      router.navigate('/clients');
    }
  }
</script>

<section class="page">
  <div class="head">
    <h1>Уведомления {#if unread > 0}<span class="unread-count">{unread}</span>{/if}</h1>
    <Button variant="ghost" loading={busy} disabled={unread === 0} onclick={markAll}>
      Отметить все прочитанными
    </Button>
  </div>

  <div class="tabs">
    <button type="button" class:active={filter === 'all'} onclick={() => setFilter('all')}>Все</button>
    <button type="button" class:active={filter === 'unread'} onclick={() => setFilter('unread')}>
      Непрочитанные{unread > 0 ? ` (${unread})` : ''}
    </button>
  </div>

  {#if error}
    <div class="alert">{error}</div>
  {/if}

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else if items.length === 0}
    <div class="empty">{filter === 'unread' ? 'Непрочитанных нет' : 'Уведомлений пока нет'}</div>
  {:else}
    <div class="list">
      {#each items as item (item.id)}
        <button type="button" class="item" class:unread={!item.is_read} onclick={() => void open(item)}>
          <span class="type" aria-hidden="true">{typeIcons[item.type] ?? '•'}</span>
          <span class="content">
            <span class="row">
              <span class="title">{item.title}</span>
              {#if !item.is_read}<span class="dot"></span>{/if}
            </span>
            {#if item.body}
              <span class="body">{item.body}</span>
            {/if}
            <span class="date" title={formatDateTime(item.created_at)}>{formatRelative(item.created_at)}</span>
          </span>
        </button>
      {/each}
    </div>

    <Pagination page={page} perPage={perPage} total={total} loading={loading} onchange={goToPage} />
  {/if}
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    flex-wrap: wrap;
  }

  h1 {
    margin: 0;
    font-size: 22px;
  }

  .unread-count {
    display: inline-block;
    min-width: 20px;
    padding: 1px 8px;
    margin-left: 4px;
    border-radius: 999px;
    background: var(--primary);
    color: #fff;
    font-size: 13px;
    vertical-align: middle;
    text-align: center;
  }

  .tabs {
    display: flex;
    gap: var(--space-2);
  }

  .tabs button {
    padding: 6px 14px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--muted);
    cursor: pointer;
    font: inherit;
    font-size: 13px;
  }

  .tabs button.active {
    border-color: var(--primary);
    color: var(--primary);
    font-weight: 500;
  }

  .list {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .item {
    display: flex;
    gap: var(--space-3);
    text-align: left;
    padding: var(--space-3) var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    cursor: pointer;
    font: inherit;
    color: inherit;
  }

  .type {
    flex: 0 0 auto;
    width: 30px;
    height: 30px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: color-mix(in srgb, var(--primary) 10%, white);
    color: var(--primary);
    font-size: 15px;
  }

  .content {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
  }

  .item.unread {
    border-left: 3px solid var(--primary);
  }

  .item:hover {
    border-color: var(--primary);
  }

  .row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .title {
    font-weight: 500;
  }

  .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--primary);
  }

  .body {
    color: var(--muted);
    font-size: 14px;
  }

  .date {
    color: var(--muted);
    font-size: 12px;
  }

  .alert {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--danger);
    font-size: 13px;
  }

  .empty {
    padding: var(--space-6);
    text-align: center;
    color: var(--muted);
    background: var(--surface);
    border: 1px dashed var(--border);
    border-radius: var(--radius-md);
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }
</style>
