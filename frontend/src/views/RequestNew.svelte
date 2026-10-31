<script lang="ts">
  import { onDestroy, onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { createRequest, getRequest, listClients, previewRequestPrices, type CreateRequestItem, type CreateRequestPayload, type CreatedClientAccess } from '../lib/api/requests';
  import { getClientCard } from '../lib/api/clients';
  import { listLevels } from '../lib/api/stocks';
  import { createDraft, deleteDraft, getDraft, updateDraft } from '../lib/api/drafts';
  import type { ClientItem } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { cart } from '../lib/stores/cart.svelte';
  import { router } from '../lib/router.svelte';
  import { ITEMS_SORT_OPTIONS, sortItems, type ItemsSortKey } from '../lib/items-sort';
  import { formatPrice } from '../lib/format';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Input from '../lib/components/ui/Input.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
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

  let priceRows = $state<{ price: number | null; sum: number | null }[]>([]);
  let priceTotal = $state<number | null>(null);
  let priceType = $state<{ id: number; title: string } | null>(null);
  let previewTimer: ReturnType<typeof setTimeout> | null = null;

  const pricesEnabled = $derived(appSettings.pricesEnabled);
  const itemsPayload = $derived(JSON.stringify(itemRows));

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

  type ClientMode = 'existing' | 'new';
  type NewClientForm = { name: string; inn: string; phone: string; email: string };

  let clientMode = $state<ClientMode>('existing');
  let newClient = $state<NewClientForm>({ name: '', inn: '', phone: '', email: '' });
  let errorCode = $state('');
  let clientAccess = $state<CreatedClientAccess | null>(null);
  let createdRequestId = $state(0);
  let accessCopied = $state(false);

  const canPickClient = $derived(auth.can('clients.view.all') || auth.can('clients.view.own'));
  const canCreateClient = $derived(canPickClient && auth.level >= 10);
  const clientRequired = $derived(auth.level >= 10);
  const hasStockErrors = $derived(itemRows.some((row) => stockError(row)));

  const newClientErrors = $derived.by((): Partial<Record<keyof NewClientForm, string>> => {
    if (clientMode !== 'new') {
      return {};
    }

    const errors: Partial<Record<keyof NewClientForm, string>> = {};
    const name = newClient.name.trim();
    const inn = newClient.inn.replace(/\s+/g, '');
    const phoneDigits = newClient.phone.replace(/\D+/g, '');
    const email = newClient.email.trim();

    if (name.length < 2) {
      errors.name = 'Укажите название клиента';
    }

    if (inn === '') {
      errors.inn = 'Укажите ИНН';
    } else if (/^\d{10}(\d{2})?$/.test(inn) === false) {
      errors.inn = 'ИНН — 10 или 12 цифр';
    }

    if (phoneDigits.length < 10 || phoneDigits.length > 15) {
      errors.phone = 'Телефон — от 10 до 15 цифр';
    }

    if (email !== '' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) === false) {
      errors.email = 'Неверный формат e-mail';
    }

    return errors;
  });

  const clientReady = $derived(
    clientMode === 'new' ? Object.keys(newClientErrors).length === 0 : clientId > 0
  );

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

    if (!appSettings.salesEnabled || clientMode === 'new') {
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

  $effect(() => {
    const payload = itemsPayload;
    void clientId;
    void clientMode;
    void pricesEnabled;

    if (previewTimer !== null) {
      clearTimeout(previewTimer);
      previewTimer = null;
    }

    if (!pricesEnabled) {
      priceRows = [];
      priceTotal = null;
      priceType = null;

      return;
    }

    if (draftLoading || submitting) {
      return;
    }

    previewTimer = setTimeout(() => void refreshPrices(payload), 350);
  });

  async function refreshPrices(payload: string): Promise<void> {
    const rows = JSON.parse(payload) as CreateRequestItem[];

    if (rows.length === 0) {
      priceRows = [];
      priceTotal = 0;
      priceType = null;

      return;
    }

    try {
      const preview = await previewRequestPrices(rows, clientId, clientMode === 'new');

      if (!preview.enabled) {
        priceRows = [];
        priceTotal = null;
        priceType = null;

        return;
      }

      priceRows = preview.items;
      priceTotal = preview.total;
      priceType = preview.type;
    } catch {
      // предпросмотр цен не критичен: сервер пересчитает цены при создании заявки
    }
  }

  onDestroy(() => {
    if (saveTimer !== null) {
      clearTimeout(saveTimer);
      saveTimer = null;
    }

    if (previewTimer !== null) {
      clearTimeout(previewTimer);
      previewTimer = null;
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
        const data = await listLevels([warehouseId], { show_zero: true }, 1, 200);

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

  function payload(): CreateRequestPayload {
    const base: CreateRequestPayload = {
      subject: subject.trim(),
      body: body.trim(),
      priority,
      items: itemRows
    };

    if (clientMode === 'new' && canCreateClient) {
      return {
        ...base,
        new_client: {
          name: newClient.name.trim(),
          inn: newClient.inn.replace(/\s+/g, ''),
          phone: newClient.phone.trim(),
          email: newClient.email.trim()
        }
      };
    }

    return { ...base, client_id: clientId > 0 ? clientId : undefined };
  }

  function setClientMode(mode: ClientMode): void {
    if (clientMode === mode || submitting) {
      return;
    }

    clientMode = mode;
    error = '';
    errorCode = '';

    if (mode === 'new') {
      selectedClient = null;
      clientId = 0;
      clientQuery = '';
      clientResults = [];
      clientOpen = false;

      if (clientTimer !== null) {
        clearTimeout(clientTimer);
        clientTimer = null;
      }
    }
  }

  function findExistingClient(): void {
    const query = (newClient.inn || newClient.phone || newClient.name).trim();

    clientMode = 'existing';
    error = '';
    errorCode = '';
    clientQuery = query;
    selectedClient = null;
    clientId = 0;
    clientResults = [];
    clientOpen = true;
    void searchClients(query);
  }

  function finishCreated(): void {
    const id = createdRequestId;
    clientAccess = null;
    createdRequestId = 0;

    if (id > 0) {
      router.navigate(`/requests/${id}`);
    }
  }

  async function copyClientAccess(): Promise<void> {
    if (clientAccess === null) {
      return;
    }

    try {
      await navigator.clipboard.writeText(
        `Логин: ${clientAccess.login}\nПароль: ${clientAccess.password}`
      );
      accessCopied = true;
      setTimeout(() => (accessCopied = false), 2000);
    } catch {
      accessCopied = false;
    }
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
      clientMode = 'existing';

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
    errorCode = '';

    if (!appSettings.salesEnabled) {
      error = 'Продажи отключены: оформление заявок недоступно';
      return;
    }

    if (hasStockErrors) {
      error = 'Есть позиции без достаточного остатка — удалите их из состава заявки';
      return;
    }

    if (clientRequired && !clientReady) {
      error =
        clientMode === 'new'
          ? 'Заполните данные нового клиента'
          : 'Выберите клиента, к которому привязать заявку';
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

      if (data.client_access) {
        clientAccess = data.client_access;
        createdRequestId = data.request.id;
        return;
      }

      router.navigate(`/requests/${data.request.id}`);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось создать заявку';
      errorCode = cause instanceof ApiError ? cause.code : '';
    } finally {
      loading = false;
      submitting = false;
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
      selectedClient = null;
      clientQuery = '';
      clientResults = [];
      clientMode = 'existing';
      newClient = { name: '', inn: '', phone: '', email: '' };
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
          <span class="alert-icon"><Icon name="info" size={16} /></span>
          <span>
            Продажи отключены: оформление заявок недоступно. Остатки складов можно смотреть в разделе
            «Номенклатура».
          </span>
        </div>
      {/if}

      <form class="form" onsubmit={submit}>
        {#if canPickClient}
          <div class="field">
            <span class="label">Клиент{#if clientRequired} <span class="req">— обязательно</span>{/if}</span>

            {#if canCreateClient}
              <div class="client-mode" role="tablist" aria-label="Как выбрать клиента">
                <button
                  type="button"
                  role="tab"
                  aria-selected={clientMode === 'existing'}
                  class:active={clientMode === 'existing'}
                  disabled={submitting}
                  onclick={() => setClientMode('existing')}
                >
                  Существующий клиент
                </button>
                <button
                  type="button"
                  role="tab"
                  aria-selected={clientMode === 'new'}
                  class:active={clientMode === 'new'}
                  disabled={submitting}
                  onclick={() => setClientMode('new')}
                >
                  Новый клиент
                </button>
              </div>
            {/if}

            {#if clientMode === 'existing'}
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
                  <span class="req">
                    {canCreateClient
                      ? 'Выберите клиента из списка или переключитесь на «Новый клиент»'
                      : 'Выберите клиента из списка'}
                  </span>
                {/if}
              {/if}
            {:else}
              <div class="new-client">
                <Input
                  label="Название клиента"
                  bind:value={newClient.name}
                  placeholder="ООО «Ромашка»"
                  error={newClientErrors.name}
                  disabled={submitting}
                  maxlength={255}
                  autocomplete="organization"
                />
                <Input
                  label="ИНН"
                  bind:value={newClient.inn}
                  placeholder="10 или 12 цифр"
                  error={newClientErrors.inn}
                  disabled={submitting}
                  inputmode="numeric"
                  maxlength={12}
                />
                <Input
                  label="Телефон"
                  bind:value={newClient.phone}
                  placeholder="+7 999 000-00-00"
                  error={newClientErrors.phone}
                  disabled={submitting}
                  type="tel"
                  inputmode="tel"
                  autocomplete="tel"
                />
                <Input
                  label="E-mail (необязательно)"
                  bind:value={newClient.email}
                  placeholder="client@example.ru"
                  error={newClientErrors.email}
                  disabled={submitting}
                  type="email"
                  inputmode="email"
                  autocomplete="email"
                />
                <p class="new-client-hint">
                  После создания для клиента будет заведена учётная запись. Логин и пароль покажем
                  один раз — их нужно передать клиенту. Контакты нового клиента в черновик не
                  сохраняются.
                </p>
              </div>
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

            <div class="items-head-actions">
              {#if itemRows.length > 1}
                <select class="items-sort" bind:value={itemsSort} aria-label="Сортировка позиций">
                  {#each ITEMS_SORT_OPTIONS as option (option.value)}
                    <option value={option.value}>{option.label}</option>
                  {/each}
                </select>
              {/if}

              <Button
                variant="ghost"
                disabled={!appSettings.salesEnabled}
                onclick={() => (pickerOpen = true)}
              >
                <Icon name="plus" size={14} />
                Добавить позицию
              </Button>
            </div>
          </div>

          {#if itemRows.length === 0}
            <div class="items-empty">
              <p class="muted">Позиций пока нет</p>
              <Button
                disabled={!appSettings.salesEnabled}
                onclick={() => (pickerOpen = true)}
              >
                <Icon name="plus" size={14} />
                Добавить позицию
              </Button>
            </div>
          {:else}
            <div class="item-rows">
              {#each sortItems(itemRows, itemsSort) as row (itemRows.indexOf(row))}
                {@const rowIndex = itemRows.indexOf(row)}
                {@const rowPrice = priceRows[rowIndex]}
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
                  {#if pricesEnabled}
                    <span class="ir-price" class:missing={!rowPrice || rowPrice.price === null}>
                      {formatPrice(rowPrice?.price ?? null)}
                    </span>
                    <span class="ir-sum">{formatPrice(rowPrice?.sum ?? null)}</span>
                  {/if}
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

        {#if pricesEnabled && itemRows.length > 0}
          <div class="price-total">
            <span class="muted">Цены: {priceType?.title ?? '—'}</span>
            <strong>Итого: {formatPrice(priceTotal)}</strong>
          </div>
        {/if}

        {#if hasStockErrors}
          <div class="alert">
            <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
            <span>
              Позиции, отмеченные красным, отсутствуют на складе. Удалите их из состава заявки или
              обратитесь к администратору, чтобы разрешить работу с отрицательными остатками.
            </span>
          </div>
        {/if}

        {#if error}
          <div class="alert" role="alert">
            <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
            <span>{error}</span>
            {#if errorCode === 'client_exists' || errorCode === 'phone_taken' || errorCode === 'email_taken'}
              <button type="button" class="alert-action" onclick={findExistingClient}>
                Найти существующего клиента
              </button>
            {/if}
          </div>
        {/if}

        <div class="modal-actions">
          <div class="modal-buttons">
            {#if draftStatusText}
              <span class="draft-status">{draftStatusText}</span>
            {/if}
            <Button
              type="submit"
              loading={loading}
              disabled={!appSettings.salesEnabled || (clientRequired && !clientReady) || hasStockErrors}
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
    clientId={clientId}
    onclose={() => (pickerOpen = false)}
    onchange={(next) => (itemRows = next)}
  />

  <Modal
    open={clientAccess !== null}
    title="Заявка создана"
    closeButton={false}
    onclose={finishCreated}
  >
    {#if clientAccess}
      <div class="access">
        <p class="access-lead">
          Для клиента <strong>{clientAccess.name}</strong> создана учётная запись.
        </p>

        <div class="access-card">
          <div class="access-row">
            <span class="access-label">Логин</span>
            <code>{clientAccess.login}</code>
          </div>
          <div class="access-row">
            <span class="access-label">Пароль</span>
            <code>{clientAccess.password}</code>
          </div>
          {#if clientAccess.email}
            <div class="access-row">
              <span class="access-label">E-mail</span>
              <span>{clientAccess.email}</span>
            </div>
          {/if}
        </div>

        <p class="access-note">
          Пароль показывается один раз — передайте его клиенту. Сменить пароль клиент сможет после
          входа в личный кабинет.
        </p>

        <div class="access-actions">
          <Button variant="ghost" onclick={() => void copyClientAccess()}>
            {accessCopied ? 'Скопировано' : 'Скопировать доступ'}
          </Button>
          <Button onclick={finishCreated}>Перейти к заявке</Button>
        </div>
      </div>
    {/if}
  </Modal>
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

  .client-mode {
    display: inline-flex;
    align-self: flex-start;
    gap: 2px;
    padding: 2px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--bg);
  }

  .client-mode button {
    padding: 5px 12px;
    border: none;
    border-radius: 3px;
    background: none;
    color: var(--muted);
    font: inherit;
    font-size: 13px;
    cursor: pointer;
    transition: background-color 0.15s ease, color 0.15s ease;
  }

  .client-mode button:hover:not(:disabled) {
    color: var(--text);
  }

  .client-mode button.active {
    background: var(--surface);
    color: var(--text);
    box-shadow: var(--shadow-sm);
  }

  .client-mode button:disabled {
    opacity: 0.6;
    cursor: default;
  }

  .new-client {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-3);
  }

  .new-client > :global(.field:first-child) {
    grid-column: 1 / -1;
  }

  .new-client-hint {
    grid-column: 1 / -1;
    margin: 0;
    font-size: 13px;
    color: var(--muted);
  }

  .alert-action {
    margin-left: auto;
    padding: 0;
    border: none;
    background: none;
    color: var(--primary);
    font: inherit;
    font-size: 13px;
    text-decoration: underline;
    cursor: pointer;
    white-space: nowrap;
  }

  .access {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .access-lead {
    margin: 0;
    font-size: 14px;
  }

  .access-card {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--bg);
  }

  .access-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-3);
    font-size: 14px;
  }

  .access-label {
    color: var(--muted);
    font-size: 13px;
  }

  .access-row code {
    font-family: var(--font-mono, monospace);
    font-size: 14px;
    word-break: break-all;
    text-align: right;
  }

  .access-note {
    margin: 0;
    font-size: 13px;
    color: var(--muted);
  }

  .access-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--space-2);
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
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .items-head-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2);
    margin-left: auto;
  }

  .items-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-5) var(--space-3);
    border: 1px dashed var(--border);
    border-radius: var(--radius-md);
    text-align: center;
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
    color: var(--danger);
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

  .ir-price,
  .ir-sum {
    min-width: 92px;
    text-align: right;
    white-space: nowrap;
    font-size: 13px;
  }

  .ir-sum {
    font-weight: 600;
  }

  .ir-price.missing {
    color: var(--muted);
  }

  .price-total {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-2);
    padding: 8px 0 0;
    border-top: 1px dashed var(--border);
  }

  .price-total strong {
    font-size: 16px;
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
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 8px 12px;
    border: 1px solid var(--error-border);
    border-radius: var(--radius-md);
    background: var(--danger-bg);
    color: var(--text);
    font-size: 14px;
  }

  .alert.sales-off {
    border-color: var(--warning);
    background: var(--warning-bg);
    margin-bottom: var(--space-3);
  }

  .alert-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
    color: var(--danger);
  }

  .alert.sales-off .alert-icon {
    color: var(--warning-text);
  }

  @media (max-width: 560px) {
    .new-client {
      grid-template-columns: minmax(0, 1fr);
    }

    .new-client > :global(.field:first-child) {
      grid-column: auto;
    }

    .new-client-hint {
      grid-column: auto;
    }

    .client-mode {
      align-self: stretch;
    }

    .client-mode button {
      flex: 1;
    }

    .item-row {
      flex-wrap: wrap;
    }

    .ir-price,
    .ir-sum {
      min-width: 0;
    }
  }
</style>
