<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    addActivity,
    addComment,
    assignRequest,
    claimRequest,
    getRequest,
    listActivityTypes,
    listManagers,
    listStatuses,
    transitionRequest
  } from '../lib/api/requests';
  import type {
    ActivityType,
    ManagerItem,
    RequestAttachment,
    RequestDetail,
    RequestStatus
  } from '../lib/api/types';
  import {
    deleteAttachment,
    downloadAttachment,
    listAttachments,
    uploadAttachment
  } from '../lib/api/attachments';
  import { router } from '../lib/router.svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import StatusBadge from '../lib/components/StatusBadge.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { formatDateTime, formatSize, priorityLabel } from '../lib/format';

  interface Props {
    id: number;
  }

  let { id }: Props = $props();

  let detail = $state<RequestDetail | null>(null);
  let statuses = $state<RequestStatus[]>([]);
  let managers = $state<ManagerItem[]>([]);
  let loading = $state(true);
  let error = $state('');
  let actionError = $state('');
  let busy = $state(false);

  let targetStatus = $state('');
  let transitionComment = $state('');
  let cancelReason = $state('');
  let showAllTimeline = $state(false);

  const isStaff = $derived(auth.level >= 10);

  let transferTarget = $state(0);
  let transferComment = $state('');

  let activityTypes = $state<ActivityType[]>([]);
  let activityType = $state('');
  let activityBody = $state('');

  let attachments = $state<RequestAttachment[]>([]);
  let uploading = $state(false);

  let commentBody = $state('');
  let commentInternal = $state(false);

  onMount(() => {
    void load();
    void listStatuses()
      .then((data) => {
        statuses = data;
      })
      .catch(() => {
        statuses = [];
      });
    void listManagers()
      .then((data) => {
        managers = data.items;
      })
      .catch(() => {
        managers = [];
      });
    void listActivityTypes()
      .then((data) => {
        activityTypes = data;
      })
      .catch(() => {
        activityTypes = [];
      });
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      detail = await getRequest(id);

      try {
        attachments = (await listAttachments(id)).items;
      } catch {
        attachments = [];
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить заявку';
    } finally {
      loading = false;
    }
  }

  async function onFileSelected(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) return;

    uploading = true;
    actionError = '';

    try {
      attachments = (await uploadAttachment(id, file)).items;
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить файл';
    } finally {
      uploading = false;
      input.value = '';
    }
  }

  async function removeFile(attachment: RequestAttachment): Promise<void> {
    busy = true;
    actionError = '';

    try {
      await deleteAttachment(attachment.id);
      attachments = attachments.filter((item) => item.id !== attachment.id);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось удалить файл';
    } finally {
      busy = false;
    }
  }

  async function downloadFile(attachment: RequestAttachment): Promise<void> {
    actionError = '';

    try {
      await downloadAttachment(attachment.id, attachment.name);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось скачать файл';
    }
  }

  async function claim(): Promise<void> {
    busy = true;
    actionError = '';

    try {
      detail = await claimRequest(id);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось взять заявку';
    } finally {
      busy = false;
    }
  }

  async function submitTransition(event: SubmitEvent): Promise<void> {
    event.preventDefault();

    if (!detail) return;

    busy = true;
    actionError = '';

    try {
      detail = await transitionRequest(id, targetStatus, transitionComment, detail.request.version);
      targetStatus = '';
      transitionComment = '';
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось сменить статус';
    } finally {
      busy = false;
    }
  }

  async function cancelRequest(): Promise<void> {
    if (!detail || cancelReason.trim().length < 3) {
      return;
    }

    busy = true;
    actionError = '';

    try {
      detail = await transitionRequest(id, 'canceled', cancelReason.trim(), detail.request.version);
      cancelReason = '';
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось отменить заявку';
    } finally {
      busy = false;
    }
  }

  const selectableStatuses = $derived(
    statuses.filter((status) => status.code !== detail?.request.status.code)
  );

  const transferableManagers = $derived(
    managers.filter((manager) => manager.id !== detail?.request.manager?.id)
  );

  async function submitTransfer(): Promise<void> {
    if (!detail) return;

    busy = true;
    actionError = '';

    try {
      detail = await assignRequest(
        id,
        transferTarget > 0 ? transferTarget : null,
        transferComment,
        detail.request.version
      );
      transferTarget = 0;
      transferComment = '';
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось передать заявку';
    } finally {
      busy = false;
    }
  }

  function returnToPrevious(): void {
    if (detail?.previous_manager) {
      transferTarget = detail.previous_manager.id;
    }
  }

  type TimelineItem =
    | {
        kind: 'status';
        id: number;
        at: string;
        user: { id: number; name: string; level: number };
        from: { code: string; title: string } | null;
        to: { code: string; title: string } | null;
        comment: string;
      }
    | {
        kind: 'activity';
        id: number;
        at: string;
        user: { id: number; name: string; level: number };
        title: string;
        body: string;
      };

  const timeline = $derived.by((): TimelineItem[] => {
    if (!detail) return [];

    const items: TimelineItem[] = [
      ...detail.history.map(
        (event): TimelineItem => ({
          kind: 'status',
          id: event.id,
          at: event.created_at,
          user: event.user,
          from: event.from,
          to: event.to,
          comment: event.comment
        })
      ),
      ...detail.activities.map(
        (activity): TimelineItem => ({
          kind: 'activity',
          id: activity.id,
          at: activity.created_at,
          user: activity.user,
          title: activity.type.title,
          body: activity.body
        })
      )
    ];

    return items.sort((a, b) => (a.at === b.at ? a.id - b.id : a.at < b.at ? -1 : 1));
  });

  async function submitActivity(): Promise<void> {
    if (!detail) return;

    busy = true;
    actionError = '';

    try {
      detail = await addActivity(id, activityType, activityBody.trim());
      activityType = '';
      activityBody = '';
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось добавить активность';
    } finally {
      busy = false;
    }
  }

  async function submitComment(event: SubmitEvent): Promise<void> {
    event.preventDefault();

    if (!detail) return;

    busy = true;
    actionError = '';

    try {
      detail = await addComment(id, commentBody, commentInternal);
      commentBody = '';
      commentInternal = false;
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось добавить комментарий';
    } finally {
      busy = false;
    }
  }

</script>

<section class="page page-wide">
  <div class="topbar">
    <button type="button" class="back" onclick={() => router.navigate('/requests')}>
      ← К списку заявок
    </button>
  </div>

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else if error}
    <div class="alert">{error}</div>
  {:else if detail}
    <div class="card">
      <div class="card-head">
        <div>
          <div class="number">{detail.request.number}</div>
          <h1>{detail.request.subject}</h1>
        </div>
        <div class="badges">
          <StatusBadge title={detail.request.status.title} color={detail.request.status.color} />
          {#if !detail.request.manager}
            <span class="search-badge">Поиск менеджера</span>
          {/if}
        </div>
      </div>

      <dl class="info">
        <div>
          <dt>Клиент</dt>
          <dd>{detail.request.client.name}</dd>
        </div>
        <div>
          <dt>Менеджер</dt>
          <dd>
            {#if detail.request.manager}
              <a class="manager-link" href={`#/users/${detail.request.manager.id}`}>
                {detail.request.manager.name}
              </a>
            {:else}
              не назначен
            {/if}
          </dd>
        </div>
        <div>
          <dt>Приоритет</dt>
          <dd>{priorityLabel(detail.request.priority)}</dd>
        </div>
        <div>
          <dt>Создана</dt>
          <dd>{formatDateTime(detail.request.created_at)}</dd>
        </div>
        <div>
          <dt>Срок</dt>
          <dd class:overdue-text={detail.request.is_overdue}>
            {formatDateTime(detail.request.due_at)}
            {#if detail.request.is_overdue}(просрочена){/if}
          </dd>
        </div>
      </dl>

      {#if detail.request.body}
        <div class="body">{detail.request.body}</div>
      {/if}

      <div class="actions">
        {#if detail.can.claim}
          <Button loading={busy} onclick={claim}>Взять в работу</Button>
        {/if}
      </div>

      {#if actionError}
        <div class="alert">{actionError}</div>
      {/if}
    </div>

    {#if detail.items && detail.items.length > 0}
      <div class="card">
        <h2>Позиции заявки</h2>
        <ul class="items">
          {#each detail.items as item (item.id)}
            <li>
              <span class="i-name">{item.name}</span>
              <span class="i-qty">
                {item.quantity.toLocaleString('ru-RU', { maximumFractionDigits: 3 })}
                {item.unit}
              </span>
              {#if item.warehouse_name}<span class="i-wh">{item.warehouse_name}</span>{/if}
            </li>
          {/each}
        </ul>
      </div>
    {/if}

    {#if detail.can.cancel}
      <div class="card">
        <h2>Отмена заявки</h2>
        <div class="form">
          <textarea bind:value={cancelReason} rows="2" placeholder="Причина отмены (обязательно)"></textarea>
          <Button
            variant="ghost"
            loading={busy}
            disabled={cancelReason.trim().length < 3}
            onclick={cancelRequest}
          >
            Отменить заявку
          </Button>
        </div>
      </div>
    {/if}

    {#if detail.can.assign}
      <div class="card">
        <h2>Передача заявки</h2>
        <div class="form">
          <select bind:value={transferTarget}>
            {#if detail.request.manager}
              <option value={0}>В поиск менеджера (снять назначение)</option>
            {/if}
            {#each transferableManagers as manager}
              <option value={manager.id}>{manager.name}</option>
            {/each}
          </select>

          <textarea
            bind:value={transferComment}
            rows="2"
            placeholder="Пояснение: почему передаёте (обязательно)"
          ></textarea>

          <div class="row">
            <Button
              loading={busy}
              disabled={transferComment.trim().length < 3}
              onclick={submitTransfer}
            >
              {transferTarget > 0 ? 'Передать' : 'В поиск менеджера'}
            </Button>

            {#if detail.previous_manager && detail.previous_manager.id !== detail.request.manager?.id}
              <Button variant="ghost" disabled={busy} onclick={returnToPrevious}>
                Вернуть: {detail.previous_manager.name}
              </Button>
            {/if}
          </div>
        </div>
      </div>
    {/if}

    {#if detail.can.transition}
      <div class="card">
        <h2>Смена статуса</h2>
        <form class="form" onsubmit={submitTransition}>
          <select bind:value={targetStatus} required>
            <option value="" disabled>Выберите статус</option>
            {#each selectableStatuses as status}
              <option value={status.code}>{status.title}</option>
            {/each}
          </select>

          <textarea
            bind:value={transitionComment}
            rows="3"
            placeholder="Комментарий к смене статуса (обязательно)"
          ></textarea>

          <Button type="submit" loading={busy} disabled={!targetStatus || transitionComment.trim().length < 3}>
            Сменить статус
          </Button>
        </form>
      </div>
    {/if}

    {#if detail.can.activity}
      <div class="card">
        <h2>Добавить активность</h2>
        <div class="form">
          <select bind:value={activityType}>
            <option value="" disabled>Выберите активность</option>
            {#each activityTypes as type}
              <option value={type.code}>{type.title}</option>
            {/each}
          </select>

          <textarea
            bind:value={activityBody}
            rows="2"
            placeholder="Комментарий (для «Другое» обязателен)"
          ></textarea>

          <Button
            loading={busy}
            disabled={!activityType || (activityType.startsWith('other') && activityBody.trim().length < 3)}
            onclick={submitActivity}
          >
            Добавить
          </Button>
        </div>
      </div>
    {/if}

    <div class="card">
      <h2>Дорожная карта</h2>
      <ol class="timeline">
        {#each (showAllTimeline ? timeline : timeline.slice(-4)) as event (`${event.kind}-${event.id}`)}
          <li>
            <div class="t-head">
              <strong>{event.user.name}</strong>
              {#if event.kind === 'status'}
                {#if event.from && event.to && event.from.code !== event.to.code}
                  <span class="t-status">{event.from.title} → {event.to.title}</span>
                {:else if event.to}
                  <span class="t-status">{event.to.title}</span>
                {/if}
              {:else}
                <span class="t-activity">{event.title}</span>
              {/if}
              <span class="t-date">{formatDateTime(event.at)}</span>
            </div>
            {#if event.kind === 'status'}
              {#if event.comment}
                <div class="t-comment">{event.comment}</div>
              {/if}
            {:else if event.body}
              <div class="t-comment">{event.body}</div>
            {/if}
          </li>
        {/each}
      </ol>

      {#if timeline.length > 4}
        <button type="button" class="expand" onclick={() => (showAllTimeline = !showAllTimeline)}>
          {showAllTimeline ? 'Свернуть' : `Показать все (${timeline.length})`}
        </button>
      {/if}
    </div>

    <div class="card">
      <h2>Файлы</h2>

      {#if attachments.length === 0}
        <p class="muted">Файлов пока нет</p>
      {/if}

      <ul class="files">
        {#each attachments as file (file.id)}
          <li>
            <div class="file-info">
              <button type="button" class="file-link" onclick={() => void downloadFile(file)}>
                {file.name}
              </button>
              <span class="file-meta">
                {formatSize(file.size)} · {file.user.name} · {formatDateTime(file.created_at)}
              </span>
            </div>
            <button type="button" class="file-del" disabled={busy} onclick={() => void removeFile(file)}>
              Удалить
            </button>
          </li>
        {/each}
      </ul>

      <label class="upload">
        <input
          type="file"
          accept=".jpg,.jpeg,.png,.webp,.pdf,.xls,.xlsx,.doc,.docx,.txt,.zip,.rar"
          onchange={onFileSelected}
          disabled={uploading}
        />
        <span>{uploading ? 'Загрузка…' : 'Прикрепить файл (до 10 МБ)'}</span>
      </label>
    </div>

    {#if isStaff && detail.views && detail.views.length > 0}
      <div class="card">
        <h2>Кто открывал заявку</h2>
        <ul class="assignments">
          {#each detail.views as view (view.id)}
            <li>
              <div class="t-head">
                <strong>{view.user.name}</strong>
                <span class="t-date">{formatDateTime(view.created_at)}</span>
              </div>
            </li>
          {/each}
        </ul>
      </div>
    {/if}

    {#if isStaff && detail.assignments.length > 0}
      <div class="card">
        <h2>Передачи заявки</h2>
        <ul class="assignments">
          {#each detail.assignments as record (record.id)}
            <li>
              <div class="t-head">
                <strong>{record.user.name}</strong>
                <span class="a-route">
                  {record.from?.name ?? 'нет'} → {record.to?.name ?? 'поиск менеджера'}
                </span>
                <span class="t-date">{formatDateTime(record.created_at)}</span>
              </div>
              {#if record.comment}
                <div class="t-comment">{record.comment}</div>
              {/if}
            </li>
          {/each}
        </ul>
      </div>
    {/if}

    {#if detail.can.comment}
    <div class="card">
      <h2>Комментарии</h2>
      <p class="muted comment-hint">Служебные пометки для сотрудников</p>

      {#if detail.comments.length === 0}
        <p class="muted">Комментариев пока нет</p>
      {/if}

      <div class="comments">
        {#each detail.comments as comment (comment.id)}
          <div class="comment" class:internal={comment.is_internal}>
            <div class="c-head">
              <strong>{comment.user.name}</strong>
              {#if comment.is_internal}<span class="internal-tag">внутренний</span>{/if}
              <span class="t-date">{formatDateTime(comment.created_at)}</span>
            </div>
            <div class="c-body">{comment.body}</div>
          </div>
        {/each}
      </div>

      <form class="form" onsubmit={submitComment}>
        <textarea bind:value={commentBody} rows="3" placeholder="Написать комментарий"></textarea>

        {#if detail.can.comment_internal}
          <label class="checkbox">
            <input type="checkbox" bind:checked={commentInternal} />
            Внутренний комментарий (не виден клиенту)
          </label>
        {/if}

        <Button type="submit" loading={busy} disabled={!commentBody.trim()}>
          Отправить
        </Button>
      </form>
    </div>
    {/if}
  {/if}
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .back {
    align-self: flex-start;
    padding: 0;
    border: none;
    background: none;
    color: var(--primary);
    cursor: pointer;
    font-size: 14px;
  }

  .topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
  }

  .manager-link {
    color: var(--primary);
    text-decoration: none;
  }

  .manager-link:hover {
    text-decoration: underline;
  }

  .expand {
    margin-top: var(--space-3);
    padding: 0;
    border: none;
    background: none;
    color: var(--primary);
    cursor: pointer;
    font: inherit;
    font-size: 13px;
  }

  .comment-hint {
    margin-top: -8px;
    font-size: 12px;
  }

  .items {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
  }

  .items li {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
  }

  .items li:last-child {
    border-bottom: none;
  }

  .i-name {
    flex: 1;
    min-width: 0;
  }

  .i-qty {
    font-weight: 500;
    white-space: nowrap;
  }

  .i-wh {
    color: var(--muted);
    font-size: 12px;
    white-space: nowrap;
  }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--space-5);
    box-shadow: var(--shadow-sm);
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-3);
  }

  .badges {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: var(--space-1);
  }

  .search-badge {
    font-size: 12px;
    color: #b45309;
    background: #fef3c7;
    border: 1px solid #fde68a;
    border-radius: 999px;
    padding: 2px 10px;
    white-space: nowrap;
  }

  .row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .assignments {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .files {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .files li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: var(--space-2) var(--space-3);
  }

  .file-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .file-link {
    padding: 0;
    border: none;
    background: none;
    color: var(--primary);
    cursor: pointer;
    font-size: 14px;
    text-align: left;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .file-meta {
    font-size: 12px;
    color: var(--muted);
  }

  .file-del {
    border: none;
    background: none;
    color: var(--danger);
    cursor: pointer;
    font-size: 13px;
    white-space: nowrap;
  }

  .file-del:disabled {
    opacity: 0.5;
  }

  .upload {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    align-self: flex-start;
    padding: 8px 14px;
    border: 1px dashed var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-size: 13px;
    color: var(--muted);
  }

  .upload input {
    display: none;
  }

  .a-route {
    color: var(--primary);
    font-size: 13px;
  }

  h1 {
    margin: 0;
    font-size: 20px;
  }

  h2 {
    margin: 0;
    font-size: 16px;
  }

  .number {
    font-size: 13px;
    color: var(--muted);
    font-weight: 600;
    margin-bottom: 2px;
  }

  .info {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: var(--space-3);
    margin: 0;
  }

  dt {
    font-size: 12px;
    color: var(--muted);
  }

  dd {
    margin: 0;
    font-weight: 500;
  }

  .overdue-text {
    color: var(--danger);
  }

  .body {
    white-space: pre-wrap;
    background: var(--bg);
    border-radius: var(--radius-sm);
    padding: var(--space-3);
  }

  .actions {
    display: flex;
    gap: var(--space-2);
  }

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  select,
  textarea {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font-family: inherit;
    font-size: inherit;
    outline: none;
    resize: vertical;
  }

  select:focus,
  textarea:focus {
    border-color: var(--primary);
  }

  .timeline {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    border-left: 2px solid var(--border);
    padding-left: var(--space-4);
  }

  .timeline li {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .t-head {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: var(--space-2);
    font-size: 14px;
  }

  .t-status {
    color: var(--primary);
    font-size: 13px;
  }

  .t-activity {
    color: #047857;
    font-size: 13px;
  }

  .t-date {
    color: var(--muted);
    font-size: 12px;
    margin-left: auto;
  }

  .t-comment {
    color: var(--text);
    font-size: 14px;
    white-space: pre-wrap;
  }

  .comments {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .comment {
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: var(--space-3);
  }

  .comment.internal {
    background: #fffbeb;
    border-color: #fde68a;
  }

  .c-head {
    display: flex;
    align-items: baseline;
    gap: var(--space-2);
    margin-bottom: 4px;
    font-size: 14px;
  }

  .c-body {
    white-space: pre-wrap;
  }

  .internal-tag {
    font-size: 11px;
    color: #b45309;
    background: #fef3c7;
    border-radius: 999px;
    padding: 1px 8px;
  }

  .checkbox {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 13px;
    color: var(--muted);
  }

  .muted {
    color: var(--muted);
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
</style>
