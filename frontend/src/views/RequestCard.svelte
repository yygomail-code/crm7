<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError, downloadFromApi } from '../lib/api/client';
  import { listMessages, markThreadRead, sendMessage } from '../lib/api/chat';
  import {
    addActivity,
    addComment,
    assignRequest,
    claimRequest,
    getRequest,
    listActivityTypes,
    listManagers,
    listStatuses,
    transitionRequest,
    updateRequestItems,
    updateRequestMeta,
    type CreateRequestItem
  } from '../lib/api/requests';
  import type {
    ActivityType,
    ChatMessage,
    ManagerItem,
    RequestAttachment,
    RequestDetail,
    RequestItemRow,
    RequestStatus
  } from '../lib/api/types';
  import ItemsPicker from '../lib/components/requests/ItemsPicker.svelte';
  import {
    deleteAttachment,
    downloadAttachment,
    listAttachments,
    uploadAttachment
  } from '../lib/api/attachments';
  import { router } from '../lib/router.svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import StatusBadge from '../lib/components/StatusBadge.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { formatDateTime, formatSize, priorityLabel } from '../lib/format';
  import { ITEMS_SORT_OPTIONS, sortItems, type ItemsSortKey } from '../lib/items-sort';

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
  let cardTab = $state<CardTab>('summary');
  let historyTab = $state<'timeline' | 'assignments' | 'views'>('timeline');
  let cancelOpen = $state(false);
  let statusOpen = $state(false);
  let actionNotice = $state('');
  let chatUnread = $state(0);

  let priorityOpen = $state(false);
  let dueOpen = $state(false);
  let metaPriority = $state(2);
  let metaDue = $state('');
  let metaDueComment = $state('');
  let metaBusy = $state(false);

  let subjectOpen = $state(false);
  let bodyOpen = $state(false);
  let subjectDraft = $state('');
  let bodyDraft = $state('');

  let itemModalOpen = $state(false);
  let itemModalId = $state(0);
  let itemModalName = $state('');
  let itemModalUnit = $state('');
  let itemModalQty = $state(1);
  let expandedItems = $state<Set<number>>(new Set());

  let deleteOpen = $state(false);
  let deleteItemId = $state(0);
  let deleteItemName = $state('');
  let deleteComment = $state('');
  let deleteFromQty = $state(false);

  function qtyText(item: RequestItemRow): string {
    const value = item.quantity.toLocaleString('ru-RU', { maximumFractionDigits: 3 });

    return item.unit ? `${value} ${item.unit}` : value;
  }

  function toggleItemDescription(itemId: number): void {
    const next = new Set(expandedItems);

    if (next.has(itemId)) {
      next.delete(itemId);
    } else {
      next.add(itemId);
    }

    expandedItems = next;
  }

  function openItemModal(item: RequestItemRow): void {
    itemModalId = item.id;
    itemModalName = item.name;
    itemModalUnit = item.unit;
    itemModalQty = item.quantity;
    itemModalOpen = true;
  }

  function changeItemQty(delta: number): void {
    itemModalQty = Math.max(0, Number(itemModalQty ?? 0) + delta);
  }

  function itemPayload(overrides: Map<number, number>, skipId = 0): CreateRequestItem[] {
    if (!detail) {
      return [];
    }

    return detail.items
      .filter((item) => item.id !== skipId)
      .map((item) => ({
        warehouse_id: item.warehouse_id,
        warehouse_name: item.warehouse_name,
        stock_level_id: item.stock_level_id,
        name: item.name,
        unit: item.unit,
        description: item.description,
        quantity: overrides.has(item.id) ? Number(overrides.get(item.id)) : item.quantity
      }))
      .filter((item) => item.quantity > 0);
  }

  async function applyItems(items: CreateRequestItem[], comment = ''): Promise<void> {
    if (!detail) {
      return;
    }

    busy = true;
    actionError = '';

    try {
      detail = await updateRequestItems(id, items, detail.request.version, comment);
      actionNotice = 'Состав обновлён';
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось изменить состав';
    } finally {
      busy = false;
    }
  }

  let itemsApplyTimer: ReturnType<typeof setTimeout> | null = null;
  let itemsApplyBusy = false;
  let pendingItems: CreateRequestItem[] | null = null;

  async function persistItems(items: CreateRequestItem[]): Promise<boolean> {
    if (!detail) {
      return false;
    }

    busy = true;
    actionError = '';

    try {
      detail = await updateRequestItems(id, items, detail.request.version, '');
      actionNotice = 'Состав обновлён';

      return true;
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось изменить состав';

      return false;
    } finally {
      busy = false;
    }
  }

  async function flushItemsApply(): Promise<void> {
    if (itemsApplyBusy || pendingItems === null || !detail) {
      return;
    }

    const next = pendingItems;
    pendingItems = null;
    itemsApplyBusy = true;

    const ok = await persistItems(next);

    if (!ok && detail) {
      try {
        detail = await getRequest(id);
        chatUnread = detail.chat.unread ?? 0;
      } catch {
        // не удалось обновить версию — повтор ниже обработает ошибку
      }

      await persistItems(next);
    }

    itemsApplyBusy = false;

    if (pendingItems !== null) {
      void flushItemsApply();
    }
  }

  function scheduleItemsApply(items: CreateRequestItem[]): void {
    itemRows = items;
    pendingItems = items;

    if (itemsApplyTimer !== null) {
      clearTimeout(itemsApplyTimer);
    }

    itemsApplyTimer = setTimeout(() => {
      itemsApplyTimer = null;
      void flushItemsApply();
    }, 500);
  }

  function closePicker(): void {
    pickerOpen = false;

    if (itemsApplyTimer !== null) {
      clearTimeout(itemsApplyTimer);
      itemsApplyTimer = null;
    }

    void flushItemsApply();
  }

  async function saveItemQty(): Promise<void> {
    const quantity = Number(itemModalQty ?? 0);

    if (quantity <= 0) {
      deleteItemId = itemModalId;
      deleteItemName = itemModalName;
      deleteComment = '';
      deleteFromQty = true;
      itemModalOpen = false;
      deleteOpen = true;
      return;
    }

    await applyItems(itemPayload(new Map([[itemModalId, quantity]])));
    itemModalOpen = false;
  }

  function removeItem(item: RequestItemRow): void {
    deleteItemId = item.id;
    deleteItemName = item.name;
    deleteComment = '';
    deleteFromQty = false;
    deleteOpen = true;
  }

  async function confirmDelete(): Promise<void> {
    await applyItems(itemPayload(new Map(), deleteItemId), deleteComment.trim());
    deleteOpen = false;
  }

  async function cancelDelete(): Promise<void> {
    const fromQty = deleteFromQty;
    deleteOpen = false;

    if (fromQty) {
      itemModalQty = 1;
      await applyItems(itemPayload(new Map([[deleteItemId, 1]])));
    }
  }
  let activityOccurredAt = $state(localNow());

  const priorityOptions: { value: number; label: string }[] = [
    { value: 1, label: 'Высокий' },
    { value: 2, label: 'Обычный' },
    { value: 3, label: 'Низкий' }
  ];
  let itemsSort = $state<ItemsSortKey>('natural');

  let itemRows = $state<CreateRequestItem[]>([]);
  let pickerOpen = $state(false);

  const isStaff = $derived(auth.level >= 10);

  let transferTarget = $state(0);
  let transferComment = $state('');

  let activityTypes = $state<ActivityType[]>([]);
  let activityType = $state('');
  let activityBody = $state('');
  let attachments = $state<RequestAttachment[]>([]);
  let uploading = $state(false);

  let commentBody = $state('');

  let chatLoading = $state(false);
  let chatBusy = $state(false);
  let chatBody = $state('');
  let chatMessages = $state<ChatMessage[]>([]);

  type CardTab = 'summary' | 'items' | 'comments' | 'chat' | 'files' | 'history' | 'action' | 'transfer';

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
      chatUnread = detail.chat.unread ?? 0;

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

  function localNow(): string {
    const date = new Date();
    const pad = (part: number): string => String(part).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
  }

  function toLocalInput(value: string | null): string {
    if (!value) {
      return '';
    }

    const date = new Date(value.replace(' ', 'T'));

    if (Number.isNaN(date.getTime())) {
      return '';
    }

    const pad = (part: number): string => String(part).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
  }

  function openSubject(): void {
    if (!detail) {
      return;
    }

    subjectDraft = detail.request.subject;
    subjectOpen = true;
  }

  function openBody(): void {
    if (!detail) {
      return;
    }

    bodyDraft = detail.request.body;
    bodyOpen = true;
  }

  async function saveSubject(): Promise<void> {
    if (!detail) {
      return;
    }

    metaBusy = true;
    actionError = '';

    try {
      detail = await updateRequestMeta(id, { subject: subjectDraft.trim() }, detail.request.version);
      subjectOpen = false;
      actionNotice = 'Название обновлено';
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось изменить название';
    } finally {
      metaBusy = false;
    }
  }

  async function saveBody(): Promise<void> {
    if (!detail) {
      return;
    }

    metaBusy = true;
    actionError = '';

    try {
      detail = await updateRequestMeta(id, { body: bodyDraft.trim() }, detail.request.version);
      bodyOpen = false;
      actionNotice = 'Описание обновлено';
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось изменить описание';
    } finally {
      metaBusy = false;
    }
  }

  function openPriority(): void {
    if (!detail) {
      return;
    }

    metaPriority = detail.request.priority;
    priorityOpen = true;
  }

  function openDue(): void {
    if (!detail) {
      return;
    }

    metaDue = toLocalInput(detail.request.due_at);
    metaDueComment = '';
    dueOpen = true;
  }

  async function savePriority(): Promise<void> {
    if (!detail) {
      return;
    }

    metaBusy = true;
    actionError = '';

    try {
      detail = await updateRequestMeta(id, { priority: metaPriority }, detail.request.version);
      priorityOpen = false;
      actionNotice = 'Приоритет обновлён';
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось изменить приоритет';
    } finally {
      metaBusy = false;
    }
  }

  async function saveDue(): Promise<void> {
    if (!detail) {
      return;
    }

    metaBusy = true;
    actionError = '';

    try {
      detail = await updateRequestMeta(
        id,
        { due_at: metaDue, comment: metaDueComment.trim() },
        detail.request.version
      );
      dueOpen = false;
      actionNotice = 'Срок обновлён';
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось изменить срок';
    } finally {
      metaBusy = false;
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
      statusOpen = false;
      actionNotice = 'Статус изменён';
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось сменить статус';
    } finally {
      busy = false;
    }
  }

  function openStatus(): void {
    if (!detail) {
      return;
    }

    targetStatus = '';
    transitionComment = '';
    statusOpen = true;
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
      cancelOpen = false;
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось отменить заявку';
    } finally {
      busy = false;
    }
  }

  const selectableStatuses = $derived(
    statuses.filter((status) => (detail?.can.transition_to ?? []).includes(status.code))
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
        transferTarget,
        transferComment,
        detail.request.version
      );
      transferTarget = 0;
      transferComment = '';
      cardTab = 'history';
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
        user: { id: number; name: string; level: number; position: string };
        from: { code: string; title: string } | null;
        to: { code: string; title: string } | null;
        comment: string;
      }
    | {
        kind: 'activity';
        id: number;
        at: string;
        user: { id: number; name: string; level: number; position: string };
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

    return items.sort((a, b) => (a.at === b.at ? b.id - a.id : a.at < b.at ? 1 : -1));
  });

  function openPicker(): void {
    if (!detail) {
      return;
    }

    if (itemsApplyTimer !== null) {
      clearTimeout(itemsApplyTimer);
      itemsApplyTimer = null;
    }

    pendingItems = null;
    itemRows = detail.items.map((item) => ({
      name: item.name,
      unit: item.unit,
      quantity: item.quantity,
      warehouse_id: item.warehouse_id,
      warehouse_name: item.warehouse_name,
      description: item.description,
      stock_level_id: item.stock_level_id
    }));
    pickerOpen = true;
  }

  async function loadChat(): Promise<void> {
    if (!detail || detail.chat.thread_id <= 0) {
      return;
    }

    chatLoading = true;

    try {
      const data = await listMessages(detail.chat.thread_id, 0, id);
      chatMessages = data.items;
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить сообщения';
    } finally {
      chatLoading = false;
    }
  }

  async function openCardTab(next: CardTab): Promise<void> {
    cardTab = next;

    if (next === 'chat') {
      await loadChat();

      if (detail && detail.chat.thread_id > 0 && chatUnread > 0) {
        chatUnread = 0;
        const lastId = chatMessages.reduce((max, message) => Math.max(max, message.id), 0);

        try {
          await markThreadRead(detail.chat.thread_id, lastId);
        } catch {
          // некритично
        }
      }
    }
  }

  async function sendChat(event: SubmitEvent): Promise<void> {
    event.preventDefault();

    if (!detail || !chatBody.trim()) {
      return;
    }

    chatBusy = true;
    actionError = '';

    try {
      await sendMessage(detail.chat.thread_id, chatBody.trim(), [], id);
      chatBody = '';
      await loadChat();
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось отправить сообщение';
    } finally {
      chatBusy = false;
    }
  }

  async function downloadChatFile(attachmentId: number, name: string): Promise<void> {
    try {
      await downloadFromApi(`/chat/attachments/${attachmentId}`, name);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось скачать файл';
    }
  }

  async function submitActivity(): Promise<void> {
    if (!detail) return;

    busy = true;
    actionError = '';

    try {
      detail = await addActivity(id, activityType, activityBody.trim(), activityOccurredAt);
      activityType = '';
      activityBody = '';
      activityOccurredAt = localNow();
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось добавить действие';
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
      detail = await addComment(id, commentBody);
      commentBody = '';
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось добавить комментарий';
    } finally {
      busy = false;
    }
  }

</script>

<section class="page page-wide">
  <div class="topbar">
    <Button variant="ghost" onclick={() => router.navigate('/requests')}>← К списку заявок</Button>

    {#if detail}
      <div class="actions">
        {#if appSettings.salesEnabled && auth.can('requests.create')}
          <Button variant="ghost" onclick={() => router.navigate(`/requests/new?copy=${id}`)}>
            Копировать заявку
          </Button>
        {/if}
        {#if detail.can.claim}
          <Button loading={busy} onclick={claim}>Взять в работу</Button>
        {/if}
        {#if detail.can.cancel}
          <Button variant="danger" onclick={() => (cancelOpen = true)}>Отмена заявки</Button>
        {/if}
      </div>
    {/if}
  </div>

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else if error}
    <div class="alert">{error}</div>
  {:else if detail}
    <div class="card">
      <div class="card-head">
        <div class="title-block">
          <div class="number">{detail.request.number}</div>
          <h1>
            {#if detail.can.edit_text}
              <button
                type="button"
                class="text-edit title-edit"
                title="Редактировать название"
                onclick={openSubject}
              >
                {detail.request.subject}
              </button>
            {:else}
              {detail.request.subject}
            {/if}
          </h1>
        </div>

        <div class="badges">
          {#if detail.can.transition_to.length > 0}
            <button
              type="button"
              class="status-button"
              title="Изменить статус"
              onclick={openStatus}
            >
              <StatusBadge title={detail.request.status.title} color={detail.request.status.color} />
            </button>
          {:else}
            <StatusBadge title={detail.request.status.title} color={detail.request.status.color} />
          {/if}
          {#if !detail.request.manager}
            <span class="search-badge">Поиск менеджера</span>
          {/if}
        </div>
      </div>

      {#if actionError}
        <div class="alert">{actionError}</div>
      {/if}

      {#if actionNotice}
        <div class="notice">{actionNotice}</div>
      {/if}
    </div>

    <div class="card-tabs tab-scroll">
      <button
        type="button"
        class="card-tab"
        class:active={cardTab === 'summary'}
        onclick={() => void openCardTab('summary')}
      >
        Сводная
      </button>
      <button
        type="button"
        class="card-tab"
        class:active={cardTab === 'items'}
        onclick={() => void openCardTab('items')}
      >
        Состав{detail.items.length > 0 ? ` (${detail.items.length})` : ''}
      </button>
      {#if detail.can.comment}
        <button
          type="button"
          class="card-tab"
          class:active={cardTab === 'comments'}
          onclick={() => void openCardTab('comments')}
        >
          Комментарии{detail.comments.length > 0 ? ` (${detail.comments.length})` : ''}
        </button>
      {/if}
      <button
        type="button"
        class="card-tab"
        class:active={cardTab === 'chat'}
        class:unread={chatUnread > 0}
        onclick={() => void openCardTab('chat')}
      >
        Чат
        {#if chatUnread > 0}
          <span class="tab-dot" title="Есть новые сообщения"></span>
        {/if}
      </button>
      <button
        type="button"
        class="card-tab"
        class:active={cardTab === 'files'}
        onclick={() => void openCardTab('files')}
      >
        Файлы{attachments.length > 0 ? ` (${attachments.length})` : ''}
      </button>
      <button
        type="button"
        class="card-tab"
        class:active={cardTab === 'history'}
        onclick={() => void openCardTab('history')}
      >
        История
      </button>
      {#if detail.can.activity}
        <button
          type="button"
          class="card-tab"
          class:active={cardTab === 'action'}
          onclick={() => void openCardTab('action')}
        >
          Добавить действие
        </button>
      {/if}
      {#if detail.can.assign}
        <button
          type="button"
          class="card-tab"
          class:active={cardTab === 'transfer'}
          onclick={() => void openCardTab('transfer')}
        >
          Передать заявку
        </button>
      {/if}
    </div>

    {#if cardTab === 'summary'}
      <div class="card">
        <dl class="info">
          <div>
            <dt>Клиент</dt>
            <dd>
              {#if isStaff}
                <a class="manager-link" href={`#/clients/${detail.request.client.id}`}>
                  {detail.request.client.name}
                </a>
              {:else}
                {detail.request.client.name}
              {/if}
              {#if detail.request.client.inn}
                <span class="client-inn">ИНН {detail.request.client.inn}</span>
              {/if}
            </dd>
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
            <dd>
              {#if detail.can.meta}
                <button type="button" class="meta-link" title="Изменить приоритет" onclick={openPriority}>
                  {priorityLabel(detail.request.priority)}
                </button>
              {:else}
                {priorityLabel(detail.request.priority)}
              {/if}
            </dd>
          </div>
          <div>
            <dt>Создана</dt>
            <dd>{formatDateTime(detail.request.created_at)}</dd>
          </div>
          <div>
            <dt>Обновлена</dt>
            <dd>{formatDateTime(detail.request.updated_at)}</dd>
          </div>
          <div>
            <dt>Срок</dt>
            <dd class:overdue-text={detail.request.is_overdue}>
              {#if detail.can.meta}
                <button type="button" class="meta-link" title="Изменить срок" onclick={openDue}>
                  {formatDateTime(detail.request.due_at)}
                </button>
              {:else}
                {formatDateTime(detail.request.due_at)}
              {/if}
              {#if detail.request.is_overdue}(просрочена){/if}
            </dd>
          </div>
          {#if detail.request.closed_at}
            <div>
              <dt>Закрыта</dt>
              <dd>{formatDateTime(detail.request.closed_at)}</dd>
            </div>
          {/if}
        </dl>

        {#if detail.request.body || detail.can.edit_text}
          {#if detail.can.edit_text}
            <button
              type="button"
              class="body text-edit body-edit"
              title="Редактировать описание"
              onclick={openBody}
            >
              {detail.request.body || 'Описание не заполнено'}
            </button>
          {:else}
            <div class="body">{detail.request.body}</div>
          {/if}
        {/if}
      </div>
    {/if}

    {#if cardTab === 'items'}
      <div class="card">
        <div class="items-head">
          <h2>Состав заявки</h2>

          <div class="items-head-right">
            {#if detail.items.length > 1}
              <select class="items-sort" bind:value={itemsSort} aria-label="Сортировка позиций">
                {#each ITEMS_SORT_OPTIONS as option (option.value)}
                  <option value={option.value}>{option.label}</option>
                {/each}
              </select>
            {/if}

            {#if detail.can.edit_items}
              <Button variant="ghost" onclick={openPicker}>
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                  <path d="M12 5v14M5 12h14" />
                </svg>
                Добавить
              </Button>
            {/if}
          </div>
        </div>

        {#if detail.items.length === 0}
          <p class="muted">Позиций пока нет</p>
        {:else}
          <div class="items-table">
            <div class="it-row it-head">
              <span>Название</span>
              <span class="it-qty-col">Количество</span>
              <span>Склад</span>
              <span class="it-del-col"></span>
            </div>

            {#each sortItems(detail.items, itemsSort) as item (item.id)}
              <div class="it-row">
                <div class="it-name-col">
                  <button
                    type="button"
                    class="item-name"
                    class:has-desc={item.description !== ''}
                    title={item.description !== ''
                      ? expandedItems.has(item.id)
                        ? 'Скрыть описание'
                        : 'Показать описание'
                      : ''}
                    onclick={() => toggleItemDescription(item.id)}
                  >
                    {item.name}
                  </button>

                  {#if expandedItems.has(item.id) && item.description !== ''}
                    <div class="it-desc">{item.description}</div>
                  {/if}
                </div>

                {#if detail.can.edit_items}
                  <button
                    type="button"
                    class="item-qty"
                    title="Изменить количество"
                    onclick={() => openItemModal(item)}
                  >
                    {qtyText(item)}
                  </button>
                {:else}
                  <span class="item-qty-static">{qtyText(item)}</span>
                {/if}

                <span class="it-wh">{item.warehouse_name || '—'}</span>

                {#if detail.can.edit_items}
                  <button
                    type="button"
                    class="it-del"
                    title="Удалить позицию"
                    aria-label={`Удалить: ${item.name}`}
                    onclick={() => removeItem(item)}
                  >
                    ✕
                  </button>
                {:else}
                  <span></span>
                {/if}
              </div>
            {/each}
          </div>
        {/if}
      </div>
    {/if}

    {#if cardTab === 'comments' && detail.can.comment}
      <div class="card">
        <h2>Комментарии</h2>
        <p class="muted comment-hint">Служебные пометки для сотрудников</p>

        {#if detail.comments.length === 0}
          <p class="muted">Комментариев пока нет</p>
        {/if}

        <div class="comments">
          {#each detail.comments as comment (comment.id)}
            <div class="comment">
              <div class="c-head">
                <strong>{comment.user.name}</strong>
                <span class="t-date">{formatDateTime(comment.created_at)}</span>
              </div>
              <div class="c-body">{comment.body}</div>
            </div>
          {/each}
        </div>

        <form class="form" onsubmit={submitComment}>
          <textarea bind:value={commentBody} rows="3" placeholder="Написать комментарий"></textarea>

          <div class="row">
            <Button type="submit" loading={busy} disabled={!commentBody.trim()}>
              Отправить
            </Button>
          </div>
        </form>
      </div>
    {/if}

    {#if cardTab === 'chat'}
      <div class="card">
        <h2>Чат по заявке</h2>
        <p class="muted chat-hint">
          Заявка {detail.request.number} · переписка с {isStaff ? 'клиентом' : 'менеджером'} ·
          <a class="manager-link" href={`#/chat?thread=${detail.chat.thread_id}`}>открыть весь диалог</a>
        </p>

        {#if chatLoading}
          <div class="center"><Spinner size={22} /></div>
        {:else if chatMessages.length === 0}
          <p class="muted">Сообщений по этой заявке пока нет</p>
        {:else}
          <div class="chat-list">
            {#each chatMessages as message (message.id)}
              <div class="chat-msg" class:mine={message.is_mine}>
                <div class="cm-head">
                  <strong>{message.user.name}</strong>
                  <span class="t-date">{formatDateTime(message.created_at)}</span>
                </div>
                {#if message.body}
                  <div class="cm-body">{message.body}</div>
                {/if}
                {#if message.attachments.length > 0}
                  <div class="cm-files">
                    {#each message.attachments as file (file.id)}
                      <button
                        type="button"
                        class="file-link"
                        onclick={() => void downloadChatFile(file.id, file.name)}
                      >
                        {file.name} ({formatSize(file.size)})
                      </button>
                    {/each}
                  </div>
                {/if}
              </div>
            {/each}
          </div>
        {/if}

        {#if detail.chat.can_post}
          <form class="chat-form" onsubmit={sendChat}>
            <textarea
              bind:value={chatBody}
              rows="2"
              maxlength="4000"
              placeholder="Сообщение по заявке"
            ></textarea>
            <div class="row">
              <Button type="submit" loading={chatBusy} disabled={!chatBody.trim()}>Отправить</Button>
            </div>
          </form>
        {:else}
          <p class="muted">Отправка сообщений в этом диалоге недоступна</p>
        {/if}
      </div>
    {/if}

    {#if cardTab === 'files'}
      <div class="card">
        <h2>Файлы заявки</h2>

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
    {/if}

    {#if cardTab === 'history'}
      <div class="card">
        <div class="tabs tab-scroll">
          <button
            type="button"
            class="tab"
            class:active={historyTab === 'timeline'}
            onclick={() => (historyTab = 'timeline')}
          >
            Хронология
          </button>
          {#if isStaff}
            <button
              type="button"
              class="tab"
              class:active={historyTab === 'assignments'}
              onclick={() => (historyTab = 'assignments')}
            >
              Передачи ({detail.assignments.length})
            </button>
            <button
              type="button"
              class="tab"
              class:active={historyTab === 'views'}
              onclick={() => (historyTab = 'views')}
            >
              Просмотры ({detail.views.length})
            </button>
          {/if}
        </div>

        {#if historyTab === 'timeline'}
          {#if timeline.length === 0}
            <p class="muted">Событий пока нет</p>
          {:else}
            <ol class="timeline">
              {#each timeline as event (`${event.kind}-${event.id}`)}
                <li>
                  <div class="t-head">
                    <strong>{event.user.name}</strong>
                    {#if event.user.position}<span class="t-pos">{event.user.position}</span>{/if}
                    {#if event.kind === 'status'}
                      <span class="t-activity">Статус изменён</span>
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
          {/if}
        {:else if historyTab === 'assignments'}
          {#if detail.assignments.length === 0}
            <p class="muted">Передач пока не было</p>
          {:else}
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
          {/if}
        {:else}
          {#if detail.views.length === 0}
            <p class="muted">Заявку ещё никто не открывал</p>
          {:else}
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
          {/if}
        {/if}
      </div>
    {/if}

    {#if cardTab === 'action' && detail.can.activity}
      <div class="card">
        <h2>Добавить действие</h2>

        <div class="form">
          <div class="action-row">
            <select bind:value={activityType}>
              <option value="" disabled>Выберите действие</option>
              {#each activityTypes as type}
                <option value={type.code}>{type.title}</option>
              {/each}
            </select>

            <label class="field action-date">
              <span>Дата и время</span>
              <input type="datetime-local" bind:value={activityOccurredAt} />
            </label>
          </div>

          <textarea
            bind:value={activityBody}
            rows="2"
            placeholder="Комментарий (для «Другое» обязателен)"
          ></textarea>

          <div class="row">
            <Button
              loading={busy}
              disabled={!activityType || (activityType.startsWith('other') && activityBody.trim().length < 3)}
              onclick={submitActivity}
            >
              Добавить
            </Button>
          </div>
        </div>
      </div>
    {/if}

    {#if cardTab === 'transfer' && detail.can.assign}
      <div class="card">
        <h2>Передача заявки</h2>
        <div class="form">
          <p class="muted">
            Текущий менеджер: {detail.request.manager?.name ?? 'не назначен'}
          </p>

          <select bind:value={transferTarget} required>
            <option value={0} disabled>Выберите менеджера</option>
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
              disabled={transferTarget <= 0 || transferComment.trim().length < 3}
              onclick={submitTransfer}
            >
              Передать заявку
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

    <Modal open={itemModalOpen} title="Количество" onclose={() => (itemModalOpen = false)}>
      <div class="form">
        <p class="muted">{itemModalName}</p>

        <div class="qty-row">
          <button type="button" class="step" aria-label="Уменьшить" onclick={() => changeItemQty(-1)}>−</button>
          <input
            class="qty-input"
            type="number"
            min="0"
            step="any"
            bind:value={itemModalQty}
            aria-label="Количество"
          />
          <button type="button" class="step" aria-label="Увеличить" onclick={() => changeItemQty(1)}>+</button>
          {#if itemModalUnit}<span class="qty-unit">{itemModalUnit}</span>{/if}

          <div class="qty-actions">
            <Button loading={busy} onclick={() => void saveItemQty()}>Сохранить</Button>
            <Button variant="ghost" disabled={busy} onclick={() => (itemModalOpen = false)}>Отмена</Button>
          </div>
        </div>
      </div>
    </Modal>

    <Modal open={deleteOpen} title="Удаление позиции" onclose={() => void cancelDelete()}>
      <div class="form">
        <p class="muted">Удалить позицию «{deleteItemName}» из состава заявки?</p>

        <textarea
          bind:value={deleteComment}
          rows="2"
          placeholder="Комментарий (не обязательно)"
        ></textarea>

        <div class="row">
          <Button variant="danger" loading={busy} onclick={() => void confirmDelete()}>Удалить</Button>
          <Button variant="ghost" disabled={busy} onclick={() => void cancelDelete()}>Отмена</Button>
        </div>
      </div>
    </Modal>

    <Modal open={statusOpen} title="Смена статуса" onclose={() => (statusOpen = false)}>
      <div class="form">
        <p class="muted">Текущий статус: {detail.request.status.title}</p>

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
            placeholder="Комментарий к смене статуса (не обязательно)"
          ></textarea>

          <div class="row">
            <Button type="submit" loading={busy} disabled={!targetStatus}>Сменить статус</Button>
          </div>
        </form>
      </div>
    </Modal>

    <Modal open={subjectOpen} title="Название заявки" onclose={() => (subjectOpen = false)}>
      <div class="form">
        <label class="field">
          <span>Название</span>
          <input type="text" bind:value={subjectDraft} placeholder="Коротко: что нужно" />
        </label>

        <div class="row">
          <Button
            loading={metaBusy}
            disabled={subjectDraft.trim() === ''}
            onclick={() => void saveSubject()}
          >
            Сохранить
          </Button>
        </div>
      </div>
    </Modal>

    <Modal open={bodyOpen} title="Описание заявки" onclose={() => (bodyOpen = false)}>
      <div class="form">
        <textarea bind:value={bodyDraft} rows="6" placeholder="Детали: объект, сроки, количество"></textarea>

        <div class="row">
          <Button loading={metaBusy} onclick={() => void saveBody()}>Сохранить</Button>
        </div>
      </div>
    </Modal>

    <Modal open={priorityOpen} title="Приоритет заявки" onclose={() => (priorityOpen = false)}>
      <div class="form">
        <div class="radio-list">
          {#each priorityOptions as option (option.value)}
            <label class="radio">
              <input type="radio" bind:group={metaPriority} value={option.value} />
              <span>{option.label}</span>
            </label>
          {/each}
        </div>

        <div class="row">
          <Button loading={metaBusy} onclick={() => void savePriority()}>Сохранить</Button>
        </div>
      </div>
    </Modal>

    <Modal open={dueOpen} title="Срок заявки" onclose={() => (dueOpen = false)}>
      <div class="form">
        <label class="field">
          <span>Дата и время</span>
          <input type="datetime-local" bind:value={metaDue} />
        </label>

        <textarea
          bind:value={metaDueComment}
          rows="2"
          placeholder="Комментарий к изменению срока (обязательно)"
        ></textarea>

        <div class="row">
          <Button
            loading={metaBusy}
            disabled={metaDue.trim() === '' || metaDueComment.trim().length < 3}
            onclick={() => void saveDue()}
          >
            Сохранить
          </Button>
        </div>
      </div>
    </Modal>

    <Modal open={cancelOpen} title="Отмена заявки" onclose={() => (cancelOpen = false)}>
      <div class="form">
        <p class="muted">Заявка будет отменена, причина сохранится в истории заявки.</p>
        <textarea bind:value={cancelReason} rows="3" placeholder="Причина отмены (обязательно)"></textarea>

        <div class="modal-actions">
          <div class="modal-buttons">
            <Button variant="ghost" disabled={busy} onclick={() => (cancelOpen = false)}>Не отменять</Button>
            <Button
              variant="danger"
              loading={busy}
              disabled={cancelReason.trim().length < 3}
              onclick={cancelRequest}
            >
              Отменить заявку
            </Button>
          </div>
        </div>
      </div>
    </Modal>

    <ItemsPicker
      open={pickerOpen}
      items={itemRows}
      onclose={closePicker}
      onchange={scheduleItemsApply}
    />
  {/if}
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
  }

  .manager-link {
    color: inherit;
    text-decoration: none;
    border-bottom: 1px dashed transparent;
  }

  .manager-link:hover {
    color: var(--primary);
    border-bottom-color: var(--primary);
  }

  .notice {
    margin-top: var(--space-3);
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: #e7f5ec;
    color: #1e6b3a;
    font-size: 13px;
  }

  .t-pos {
    color: var(--muted);
    font-size: 12px;
  }

  .step {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface);
    color: var(--text);
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
  }

  .step:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .modal-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2);
    margin-top: var(--space-3);
  }

  .modal-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    margin-left: auto;
  }

  .comment-hint {
    margin-top: -8px;
    font-size: 12px;
  }

  .items-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .items-sort {
    max-width: 250px;
    padding: 6px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--muted);
    font: inherit;
    font-size: 13px;
  }

  .items-table {
    display: flex;
    flex-direction: column;
    margin: 0 -12px;
  }

  .items-head-right {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .it-row {
    display: grid;
    grid-template-columns: minmax(180px, 2fr) 150px minmax(120px, 1fr) 36px;
    align-items: center;
    gap: var(--space-3);
    padding: 8px 12px;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
    transition: background 0.12s ease;
  }

  .it-row:not(.it-head):hover {
    background: var(--bg);
  }

  .it-row:last-child {
    border-bottom: none;
  }

  .it-head {
    color: var(--muted);
    font-size: 12px;
    border-bottom-width: 2px;
  }

  .it-name-col {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
  }

  .item-name {
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
    overflow-wrap: anywhere;
  }

  .item-name.has-desc {
    border-bottom: 1px dashed var(--border);
  }

  .item-name:hover {
    color: var(--primary);
  }

  .it-desc {
    padding: 8px 10px;
    border-radius: var(--radius-sm);
    background: var(--bg);
    color: var(--muted);
    font-size: 13px;
    white-space: pre-wrap;
  }

  .client-inn {
    margin-left: var(--space-2);
    color: var(--muted);
    font-size: 13px;
    white-space: nowrap;
  }

  .it-qty-col {
    text-align: right;
  }

  .item-qty,
  .item-qty-static {
    justify-self: end;
    font-weight: 500;
    white-space: nowrap;
  }

  .item-qty {
    padding: 4px 10px;
    border: 1px dashed var(--border);
    border-radius: var(--radius-sm);
    background: none;
    font: inherit;
    font-weight: 500;
    color: inherit;
    cursor: pointer;
  }

  .item-qty:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .it-wh {
    color: var(--muted);
    font-size: 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .it-del {
    justify-self: end;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface);
    color: var(--muted);
    font-size: 13px;
    line-height: 1;
    cursor: pointer;
  }

  .it-del:hover {
    border-color: var(--danger);
    color: var(--danger);
  }

  .qty-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .qty-actions {
    display: flex;
    gap: var(--space-2);
    margin-left: auto;
  }

  .qty-input {
    width: 110px;
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    text-align: center;
  }

  .qty-input:focus {
    border-color: var(--primary);
    outline: none;
  }

  .qty-unit {
    color: var(--muted);
    font-size: 13px;
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

  .status-button {
    padding: 0;
    border: none;
    background: none;
    cursor: pointer;
    transition: opacity 0.12s ease;
  }

  .status-button:hover {
    opacity: 0.75;
  }

  .search-badge {
    font-size: 12px;
    color: #b45309;
    background: #fef3c7;
    border: 1px solid #fde68a;
    border-radius: 999px;
    padding: 2px 10px;
  }

  .card-tabs {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .card-tab {
    padding: 7px 16px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--muted);
    cursor: pointer;
    font: inherit;
    font-size: 13px;
  }

  .card-tab.unread {
    border-color: var(--primary);
    color: var(--primary);
    font-weight: 500;
  }

  .tab-dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    margin-left: 6px;
    border-radius: 50%;
    background: var(--primary);
    vertical-align: middle;
  }

  .card-tab.active {
    border-color: var(--primary);
    color: var(--primary);
    background: color-mix(in srgb, var(--primary) 8%, white);
  }

  .tabs {
    display: flex;
    gap: var(--space-2);
    margin-bottom: var(--space-3);
    border-bottom: 1px solid var(--border);
  }

  .tab {
    padding: 8px 4px;
    margin-bottom: -1px;
    border: none;
    border-bottom: 2px solid transparent;
    background: none;
    font: inherit;
    font-size: 13px;
    color: var(--muted);
    cursor: pointer;
  }

  .tab:hover {
    color: var(--text);
  }

  .tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
    font-weight: 600;
  }

  .chat-hint {
    margin-top: -4px;
  }

  .chat-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    max-height: 46vh;
    overflow-y: auto;
    padding-right: 4px;
  }

  .chat-msg {
    padding: var(--space-3);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
  }

  .chat-msg.mine {
    background: color-mix(in srgb, var(--primary) 6%, var(--surface));
    border-color: color-mix(in srgb, var(--primary) 30%, var(--border));
  }

  .cm-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-2);
    margin-bottom: 4px;
  }

  .cm-body {
    white-space: pre-wrap;
    word-break: break-word;
  }

  .cm-files {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 6px;
  }

  .chat-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    margin-top: var(--space-3);
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
    margin-top: var(--space-3);
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
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

  .c-head {
    display: flex;
    align-items: baseline;
    gap: var(--space-2);
    margin-bottom: 4px;
    font-size: 14px;
  }

  .c-body {
    white-space: pre-wrap;
    margin-top: var(--space-3);
  }

  .meta-link {
    display: inline-flex;
    align-items: baseline;
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    cursor: pointer;
    border-bottom: 1px dashed transparent;
  }

  .meta-link:hover {
    color: var(--primary);
    border-bottom-color: var(--primary);
  }

  .radio-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 13px;
    color: var(--muted);
  }

  .action-row {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: var(--space-3);
  }

  .action-row select {
    flex: 1 1 240px;
    min-width: 0;
  }

  .action-date {
    margin-left: auto;
  }

  .action-date input {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    color: var(--text);
  }

  .field input[type='text'] {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    color: var(--text);
  }

  .field input[type='text']:focus {
    border-color: var(--primary);
    outline: none;
  }

  .title-block {
    min-width: 0;
    flex: 1;
  }

  h1 {
    margin: 0;
    overflow-wrap: anywhere;
  }

  .text-edit {
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
  }

  .title-edit {
    overflow-wrap: anywhere;
  }

  .title-edit:hover,
  .body-edit:hover {
    color: var(--primary);
  }

  .body-edit {
    display: block;
    width: 100%;
    white-space: pre-wrap;
    background: var(--bg);
  }

  .radio {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 14px;
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
