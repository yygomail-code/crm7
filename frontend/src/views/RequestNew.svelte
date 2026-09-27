<script lang="ts">
  import { onDestroy, onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { createRequest, getRequest, listClients, type CreateRequestItem } from '../lib/api/requests';
  import { getClientCard } from '../lib/api/clients';
  import { listLevels } from '../lib/api/stocks';
  import { createDraft, deleteDraft, getDraft, updateDraft } from '../lib/api/drafts';
  import type { ClientItem } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { cart } from '../lib/stores/cart.svelte';
  import { router } from '../lib/router.svelte';
  import { ITEMS_SORT_OPTIONS, sortItems, type ItemsSortKey } from '../lib/items-sort';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Input from '../lib/components/ui/Input.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import ItemsPicker from '../lib/components/requests/ItemsPicker.svelte';

  let subject = $state('');
  let body = $state('');
  let priority = $state(2);
  let clientId = $state(0);
  let error = $state('');
  let loading = $state(false);
  let itemRows = $state<CreateRequestItem[]>([]);
  let stockMap = $state<Record<number, number>>({});
  let itemsSort = $state<ItemsSortKey>('natural');
  let pickerOpen = $state(false);
  let draftId = $state<number | null>(null);
  let draftLoading = $state(false);
  let draftBusy = $state(false);
  let draftSaving = $state(false);
  let draftSavedAt = $state<Date | null>(null);
  let draftDeleted = $state(false);
  let lastSaved = $state('');
  let submitting = $state(false);
  let saveTimer: ReturnType<typeof setTimeout> | null = null;

  type PickedClient = {
    id: number;
    name: string;
    company: string;
    inn: string;
    phone: string;
    email: string;
  };

  let clientQuery = $state('');
  let clientResults = $state<ClientItem[]>([]);
  let clientOpen = $state(false);
  let clientSearching = $state(false);
  let selectedClient = $state<PickedClient | null>(null);
  let clientTimer: ReturnType<typeof setTimeout> | null = null;

  const canPickClient = $derived(auth.can('clients.view.all') || auth.can('clients.view.own'));
  const clientRequired = $derived(auth.level >= 10);
  const hasStockErrors = $derived(itemRows.some((row) => stockError(row)));

  const PRISTINE_PAYLOAD = JSON.stringify({
    subject: '',
    body: '',
    priority: 2,
    client_id: 0,
    items: []
  });

  const draftPayload = $derived(
    JSON.stringify({
      subject: subject.trim(),
      body: body.trim(),
      priority,
      client_id: clientId,
      items: itemRows
    })
  );

  const draftStatusText = $derived.by(() => {
    if (draftSaving) {
      return 'Сохранение…';
    }

    if (draftDeleted) {
      return 'Черновик удалён';
    }

    if (draftSavedAt !== null) {
      const time = `${String(draftSavedAt.getHours()).padStart(2, '0')}:${String(draftSavedAt.getMinutes()).padStart(2, '0')}`;
      const today = new Date();

      return draftSavedAt.toDateString() === today.toDateString()
        ? `Черновик сохранён в ${time}`
        : `Черновик сохранён ${draftSavedAt.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })} в ${time}`;
    }

    return '';
  });

  $effect(() => {
    const payload = draftPayload;

    if (!appSettings.salesEnabled) {
      return;
    }

    if (draftLoading || submitting || draftSaving || payload === lastSaved || payload === PRISTINE_PAYLOAD) {
      return;
    }

    if (saveTimer !== null) {
      clearTimeout(saveTimer);
    }

    saveTimer = setTimeout(() => void autoSave(payload), 1500);
  });

  onDestroy(() => {
    if (saveTimer !== null) {
      clearTimeout(saveTimer);
      saveTimer = null;
    }

    if (clientTimer !== null) {
      clearTimeout(clientTimer);
      clientTimer = null;
    }
  });

  onMount(() => {
    const query = router.current.query;
    const requestedDraft = Number(query.get('draft') ?? 0);

    if (requestedDraft > 0) {
      void openDraft(requestedDraft);

      return;
    }

    const copyId = Number(query.get('copy') ?? 0);

    if (copyId > 0) {
      void openCopy(copyId);

      return;
    }

    if (query.get('from') === 'cart' && cart.items.length > 0) {
      itemRows = cart.items.map((item) => ({
        warehouse_id: item.warehouseId,
        warehouse_name: item.warehouseName,
        stock_level_id: item.stockLevelId ?? null,
        name: item.name,
        unit: item.unit,
        quantity: item.quantity
      }));
    }
  });

  function changeRowQuantity(target: CreateRequestItem, delta: number): void {
    itemRows = itemRows.map((row) =>
      row === target
        ? { ...row, quantity: Math.max(0, Number(row.quantity ?? 0) + delta) }
        : row
    );
  }

  function removeItemRow(target: CreateRequestItem): void {
    itemRows = itemRows.filter((row) => row !== target);
  }

  async function openCopy(sourceId: number): Promise<void> {
    draftLoading = true;
    error = '';

    try {
      const detail = await getRequest(sourceId);

      subject = detail.request.subject;
      body = detail.request.body ?? '';
      priority = detail.request.priority ?? 2;
      clientId = detail.request.client.id;
      selectedClient = {
        id: detail.request.client.id,
        name: detail.request.client.name,
        company: '',
        inn: detail.request.client.inn,
        phone: detail.request.client.phone,
        email: detail.request.client.email
      };
      clientQuery = clientLabel(selectedClient);
      itemRows = detail.items.map((item) => ({
        warehouse_id: item.warehouse_id,
        warehouse_name: item.warehouse_name,
        stock_level_id: item.stock_level_id,
        name: item.name,
        unit: item.unit,
        description: item.description,
        quantity: item.quantity
      }));
      lastSaved = draftPayload;

      await loadStock();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось скопировать заявку';
    } finally {
      draftLoading = false;
    }
  }

  async function loadStock(): Promise<void> {
    const warehouseIds = Array.from(
      new Set(
        itemRows
          .filter((row) => row.stock_level_id != null && row.warehouse_id != null)
          .map((row) => row.warehouse_id as number)
      )
    );
    const map: Record<number, number> = {};

    for (const warehouseId of warehouseIds) {
      try {
        const data = await listLevels(warehouseId, { show_zero: true }, 1, 200);

        for (const level of data.items) {
          map[level.id] = level.quantity;
        }
      } catch {
        // склад недоступен — позиции останутся непроверенными
      }
    }

    stockMap = map;
  }

  function availableStock(row: CreateRequestItem): number | null {
    if (row.stock_level_id == null) {
      return null;
    }

    const available = stockMap[row.stock_level_id];

    return available === undefined ? null : available;
  }

  function stockError(row: CreateRequestItem): boolean {
    if (appSettings.allowZeroStock) {
      return false;
    }

    const available = availableStock(row);

    return available !== null && available < Number(row.quantity ?? 0);
  }

  function stockErrorText(row: CreateRequestItem): string {
    const available = availableStock(row);

    if (available === null) {
      return 'нет остатка';
    }

    return available <= 0
      ? 'нет в наличии'
      : `остаток: ${available.toLocaleString('ru-RU', { maximumFractionDigits: 3 })}`;
  }

  function payload(): {
    subject: string;
    body: string;
    priority: number;
    client_id: number | undefined;
    items: CreateRequestItem[];
  } {
    return {
      subject: subject.trim(),
      body: body.trim(),
      priority,
      client_id: clientId > 0 ? clientId : undefined,
      items: itemRows
    };
  }

  function clientLabel(client: PickedClient): string {
    return client.company ? `${client.name} — ${client.company}` : client.name;
  }

  async function searchClients(query: string): Promise<void> {
    clientSearching = true;

    try {
      const data = await listClients(query, 1, 15);
      clientResults = data.items.filter((client) => client.active);
    } catch {
      clientResults = [];
    } finally {
      clientSearching = false;
    }
  }

  function onClientInput(): void {
    if (selectedClient !== null && clientQuery.trim() !== clientLabel(selectedClient)) {
      selectedClient = null;
      clientId = 0;
    }

    clientOpen = true;

    if (clientTimer !== null) {
      clearTimeout(clientTimer);
    }

    clientTimer = setTimeout(() => void searchClients(clientQuery.trim()), 250);
  }

  function onClientFocus(): void {
    clientOpen = true;

    if (clientResults.length === 0 && !clientSearching) {
      void searchClients(clientQuery.trim());
    }
  }

  function selectClient(client: ClientItem): void {
    selectedClient = {
      id: client.id,
      name: client.name,
      company: client.company,
      inn: client.inn,
      phone: client.phone,
      email: client.email
    };
    clientId = client.id;
    clientQuery = clientLabel(selectedClient);
    clientResults = [];
    clientOpen = false;
  }

  function clearClient(): void {
    selectedClient = null;
    clientId = 0;
    clientQuery = '';
    clientResults = [];
    clientOpen = true;
    void searchClients('');
  }

  function onClientKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter') {
      event.preventDefault();

      if (clientOpen && clientResults.length > 0) {
        selectClient(clientResults[0]);
      }
    } else if (event.key === 'Escape') {
      clientOpen = false;
    }
  }

  async function openDraft(id: number): Promise<void> {
    draftLoading = true;
    error = '';

    try {
      const draft = await getDraft(id);

      subject = draft.subject;
      body = draft.body;
      priority = draft.priority;
      clientId = draft.client_id;
      itemRows = draft.items;
      draftId = draft.id;
      draftDeleted = false;

      if (clientId > 0) {
        void getClientCard(clientId)
          .then((card) => {
            selectedClient = { ...card.client };
            clientQuery = clientLabel(selectedClient);
          })
          .catch(() => {
            selectedClient = null;
            clientQuery = '';
          });
      } else {
        selectedClient = null;
        clientQuery = '';
      }

      const updatedAt = new Date(draft.updated_at.replace(' ', 'T'));
      draftSavedAt = Number.isNaN(updatedAt.getTime()) ? new Date() : updatedAt;
      lastSaved = draftPayload;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось открыть черновик';
    } finally {
      draftLoading = false;
    }
  }

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    error = '';

    if (!appSettings.salesEnabled) {
      error = 'Продажи отключены: оформление заявок недоступно';
      return;
    }

    if (hasStockErrors) {
      error = 'Есть позиции без достаточного остатка — удалите их из состава заявки';
      return;
    }

    loading = true;
    submitting = true;

    if (saveTimer !== null) {
      clearTimeout(saveTimer);
      saveTimer = null;
    }

    try {
      const data = await createRequest(payload());

      if (draftId !== null) {
        try {
          await deleteDraft(draftId);
        } catch {
          // черновик можно удалить позже
        }
      }

      if (router.current.query.get('from') === 'cart') {
        cart.clear();
      }

      router.navigate(`/requests/${data.request.id}`);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось создать заявку';
      submitting = false;
    } finally {
      loading = false;
    }
  }

  async function autoSave(payload: string): Promise<void> {
    if (submitting) {
      return;
    }

    draftSaving = true;

    try {
      const data = JSON.parse(payload) as {
        subject: string;
        body: string;
        priority: number;
        client_id: number;
        items: CreateRequestItem[];
      };

      const saved = draftId === null
        ? await createDraft(data)
        : await updateDraft(draftId, data);

      draftId = saved.id;
      draftDeleted = false;
      lastSaved = payload;
      draftSavedAt = new Date();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить черновик';
    } finally {
      draftSaving = false;
    }
  }

  async function removeDraft(): Promise<void> {
    if (draftId === null || !confirm('Удалить черновик?')) {
      return;
    }

    draftBusy = true;
    error = '';

    try {
      await deleteDraft(draftId);
      draftId = null;
      subject = '';
      body = '';
      priority = 2;
      clientId = 0;
      itemRows = [];
      lastSaved = '';
      draftSavedAt = null;
      draftDeleted = true;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось удалить черновик';
    } finally {
      draftBusy = false;
    }
  }
</script>

<section class="page">
  <div class="back-row">
    <Button variant="ghost" onclick={() => router.navigate('/requests')}>← К списку заявок</Button>
  </div>

  <div class="card">
    <div class="card-head">
      <h1>{draftId === null ? 'Новая заявка' : 'Черновик заявки'}</h1>

      <div class="head-right">
        {#if draftId !== null}
          <button
            type="button"
            class="draft-remove"
            title="Удалить черновик"
            aria-label="Удалить черновик"
            disabled={draftBusy}
            onclick={() => void removeDraft()}
          >
            <Icon name="trash" size={18} />
          </button>
        {/if}
      </div>
    </div>

    {#if draftLoading}
      <div class="center"><Spinner size={24} /></div>
    {:else}
      {#if !appSettings.salesEnabled}
        <div class="alert sales-off">
          Продажи отключены: оформление заявок недоступно. Остатки складов можно смотреть в разделе
          «Складские остатки».
        </div>
      {/if}

      <form class="form" onsubmit={submit}>
        {#if canPickClient}
          <div class="field">
            <span class="label">Клиент{#if clientRequired} <span class="req">— обязательно</span>{/if}</span>

            {#if selectedClient}
              <div class="client-selected">
                <div class="cs-main">
                  <span class="cs-name">
                    {selectedClient.name}{selectedClient.company ? ` — ${selectedClient.company}` : ''}
                  </span>
                  <span class="cs-meta">
                    {#if selectedClient.inn}ИНН {selectedClient.inn}{/if}
                    {#if selectedClient.inn && (selectedClient.phone || selectedClient.email)} · {/if}
                    {selectedClient.phone || selectedClient.email || ''}
                  </span>
                </div>
                <button
                  type="button"
                  class="cs-clear"
                  aria-label="Сменить клиента"
                  title="Сменить клиента"
                  onclick={clearClient}
                >
                  ✕
                </button>
              </div>
            {:else}
              <div class="client-search">
                <input
                  class="text-input"
                  type="text"
                  bind:value={clientQuery}
                  placeholder="Имя, компания, ИНН, телефон или e-mail"
                  autocomplete="off"
                  oninput={onClientInput}
                  onfocus={onClientFocus}
                  onkeydown={onClientKeydown}
                />

                {#if clientOpen}
                  <div class="client-list" role="listbox">
                    {#if clientSearching}
                      <div class="cl-empty">Поиск…</div>
                    {:else if clientResults.length === 0}
                      <div class="cl-empty">Ничего не найдено — уточните запрос</div>
                    {:else}
                      {#each clientResults as client (client.id)}
                        <button
                          type="button"
                          class="cl-item"
                          role="option"
                          aria-selected="false"
                          onmousedown={(event) => event.preventDefault()}
                          onclick={() => selectClient(client)}
                        >
                          <span class="cl-name">
                            {client.name}{client.company ? ` — ${client.company}` : ''}
                          </span>
                          <span class="cl-meta">
                            {#if client.inn}ИНН {client.inn}{/if}
                            {#if client.inn && client.phone} · {/if}
                            {client.phone ?? ''}
                            {#if (client.inn || client.phone) && client.email} · {/if}
                            {client.email ?? ''}
                          </span>
                        </button>
                      {/each}
                    {/if}
                  </div>
                {/if}
              </div>

              {#if clientRequired}
                <span class="req">Выберите клиента из списка — ввод вручную не подойдёт</span>
              {/if}
            {/if}
          </div>
        {/if}

        <Input label="Тема заявки" bind:value={subject} placeholder="Коротко: что нужно" />

        <label class="field">
          <span class="label">Описание</span>
          <textarea bind:value={body} rows="5" placeholder="Детали: объект, сроки, количество"></textarea>
        </label>

        <label class="field">
          <span class="label">Приоритет</span>
          <select bind:value={priority}>
            <option value={1}>Низкий</option>
            <option value={2}>Обычный</option>
            <option value={3}>Высокий</option>
          </select>
        </label>

        <div class="items-block">
          <div class="items-head">
            <span class="label">
              Состав заявки{#if itemRows.length > 0} — {itemRows.length}{/if}
            </span>

            {#if itemRows.length > 1}
              <select class="items-sort" bind:value={itemsSort} aria-label="Сортировка позиций">
                {#each ITEMS_SORT_OPTIONS as option (option.value)}
                  <option value={option.value}>{option.label}</option>
                {/each}
              </select>
            {/if}
          </div>

          {#if itemRows.length === 0}
            <p class="muted">Позиций пока нет — добавьте через «Складские остатки»</p>
          {:else}
            <div class="item-rows">
              {#each sortItems(itemRows, itemsSort) as row (itemRows.indexOf(row))}
                <div class="item-row" class:stock-error={stockError(row)}>
                  <div class="ir-main">
                    <span class="ir-name">{row.name}</span>
                    {#if row.warehouse_name}<span class="ir-wh">{row.warehouse_name}</span>{/if}
                    {#if stockError(row)}
                      <span class="ir-stock-warn">{stockErrorText(row)}</span>
                    {/if}
                  </div>
                  <div class="ir-qty-box">
                    <button
                      type="button"
                      class="step"
                      aria-label={`Уменьшить: ${row.name}`}
                      onclick={() => changeRowQuantity(row, -1)}
                    >
                      −
                    </button>
                    <input
                      class="text-input ir-qty"
                      type="number"
                      min="0"
                      step="any"
                      bind:value={row.quantity}
                      aria-label={`Количество: ${row.name}`}
                    />
                    <button
                      type="button"
                      class="step"
                      aria-label={`Увеличить: ${row.name}`}
                      onclick={() => changeRowQuantity(row, 1)}
                    >
                      +
                    </button>
                  </div>
                  {#if row.unit}<span class="ir-unit">{row.unit}</span>{/if}
                  <button
                    type="button"
                    class="ir-del"
                    aria-label={`Убрать: ${row.name}`}
                    onclick={() => removeItemRow(row)}
                  >
                    ✕
                  </button>
                </div>
              {/each}
            </div>
          {/if}
        </div>

        {#if hasStockErrors}
          <div class="alert">
            Позиции, отмеченные красным, отсутствуют на складе. Удалите их из состава заявки или
            обратитесь к администратору, чтобы разрешить работу с отрицательными остатками.
          </div>
        {/if}

        {#if error}
          <div class="alert">{error}</div>
        {/if}

        <div class="modal-actions">
          <Button variant="ghost" disabled={!appSettings.salesEnabled} onclick={() => (pickerOpen = true)}>
            Добавить позицию
          </Button>
          <div class="modal-buttons">
            {#if draftStatusText}
              <span class="draft-status">{draftStatusText}</span>
            {/if}
            <Button
              type="submit"
              loading={loading}
              disabled={!appSettings.salesEnabled || (clientRequired && clientId === 0) || hasStockErrors}
            >
              Создать заявку
            </Button>
          </div>
        </div>
      </form>
    {/if}
  </div>

  <ItemsPicker
    open={pickerOpen}
    items={itemRows}
    onclose={() => (pickerOpen = false)}
    onchange={(next) => (itemRows = next)}
  />
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    max-width: 720px;
    margin: 0 auto;
  }

  .back-row {
    display: flex;
    align-self: flex-start;
  }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--space-5);
    box-shadow: var(--shadow-sm);
  }

  .card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    margin-bottom: var(--space-3);
  }

  h1 {
    margin: 0;
    font-size: 20px;
  }

  .head-right {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .draft-status {
    color: var(--muted);
    font-size: 13px;
  }

  .draft-remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface);
    color: var(--muted);
    cursor: pointer;
  }

  .draft-remove:hover:not(:disabled) {
    border-color: var(--danger);
    color: var(--danger);
  }

  .draft-remove:disabled {
    opacity: 0.6;
    cursor: default;
  }

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .field {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
  }

  .client-search {
    position: relative;
  }

  .client-search .text-input {
    width: 100%;
  }

  .client-list {
    position: absolute;
    z-index: 20;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    max-height: 320px;
    overflow-y: auto;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-md);
  }

  .cl-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
    width: 100%;
    padding: 8px 12px;
    border: none;
    border-bottom: 1px solid var(--border);
    background: none;
    text-align: left;
    font: inherit;
    color: inherit;
    cursor: pointer;
  }

  .cl-item:last-child {
    border-bottom: none;
  }

  .cl-item:hover {
    background: var(--bg);
  }

  .cl-name {
    font-size: 14px;
  }

  .cl-meta {
    font-size: 12px;
    color: var(--muted);
  }

  .cl-empty {
    padding: 10px 12px;
    font-size: 13px;
    color: var(--muted);
  }

  .client-selected {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: 8px 12px;
    border: 1px solid var(--primary);
    border-radius: var(--radius-sm);
    background: color-mix(in srgb, var(--primary) 6%, var(--surface));
  }

  .cs-main {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
    min-width: 0;
  }

  .cs-name {
    font-size: 14px;
  }

  .cs-meta {
    font-size: 12px;
    color: var(--muted);
  }

  .cs-clear {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface);
    color: var(--muted);
    font-size: 13px;
    line-height: 1;
    cursor: pointer;
  }

  .cs-clear:hover {
    border-color: var(--danger);
    color: var(--danger);
  }

  .items-block {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
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

  .label {
    font-size: 13px;
    color: var(--muted);
  }

  .req {
    color: var(--danger, #d64545);
    font-size: 12px;
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

  .text-input {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    outline: none;
  }

  .text-input:focus {
    border-color: var(--primary);
  }

  .muted {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-4) 0;
  }

  .item-rows {
    display: flex;
    flex-direction: column;
  }

  .item-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: 6px 0;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
  }

  .item-row.stock-error {
    padding-inline: 8px;
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
  }

  .ir-stock-warn {
    color: var(--danger);
    font-size: 12px;
    white-space: nowrap;
  }

  .ir-main {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 0;
  }

  .ir-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .ir-wh {
    font-size: 12px;
    color: var(--muted);
  }

  .ir-qty-box {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .ir-qty {
    width: 72px;
    text-align: center;
  }

  .ir-unit {
    color: var(--muted);
    font-size: 13px;
  }

  .ir-del {
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

  .ir-del:hover {
    border-color: var(--danger);
    color: var(--danger);
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
    margin-top: var(--space-2);
  }

  .modal-buttons {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2);
    margin-left: auto;
  }

  .alert {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--danger);
    font-size: 13px;
  }

  .alert.sales-off {
    background: #fdf3e3;
    color: #8a5a11;
    margin-bottom: var(--space-3);
  }

</style>
