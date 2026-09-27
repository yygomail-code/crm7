<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    acceptTransfer,
    activateClient,
    assignClient,
    blockClient,
    cancelTransfer,
    claimClient,
    declineTransfer,
    getClientCard,
    getClientInterests,
    rejectClient,
    transferClient,
    unblockClient,
    updateClientContacts
  } from '../lib/api/clients';
  import { listManagers, listRequests, requestFilters } from '../lib/api/requests';
  import type { ClientCard, ClientInterest, ManagerItem, RequestItem, RequestStatus } from '../lib/api/types';
  import { todayIso } from '../lib/period';
  import { tooltip } from '../lib/actions/tooltip';
  import Button from '../lib/components/ui/Button.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import Pagination from '../lib/components/ui/Pagination.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import StatusBadge from '../lib/components/StatusBadge.svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import { router } from '../lib/router.svelte';

  interface Props {
    id: number;
  }

  let { id }: Props = $props();

  let card = $state<ClientCard | null>(null);
  let loading = $state(true);
  let error = $state('');
  let actionError = $state('');
  let actionNotice = $state('');
  let busy = $state(false);
  let editOpen = $state(false);
  let tab = $state<'summary' | 'requests' | 'interests' | 'history'>('summary');
  let editBusy = $state(false);
  let editForm = $state({ name: '', company: '', inn: '', position: '', phone: '', email: '' });
  let managerOpen = $state(false);
  let transferOpen = $state(false);
  let managerBusy = $state(false);
  let managers = $state<ManagerItem[]>([]);
  let interests = $state<ClientInterest[]>([]);
  let interestsTotal = $state(0);
  let interestsPage = $state(1);
  let interestsPerPage = $state(20);
  let interestsQuery = $state('');
  let interestsInput = $state('');
  let interestsSort = $state('qty_desc');
  let interestsLoading = $state(false);
  let requestsState = $state<'' | 'open' | 'closed' | 'overdue'>('');
  let requestsStatus = $state('');
  let requestsQuery = $state('');
  let requestsInput = $state('');
  let requestsItems = $state<RequestItem[]>([]);
  let requestsTotal = $state(0);
  let requestsPage = $state(1);
  let requestsPerPage = $state(20);
  let requestsLoading = $state(false);
  let historyPage = $state(1);
  let historyPerPage = $state(20);
  let statuses = $state<RequestStatus[]>([]);
  let managerForm = $state({ manager_id: '', comment: '' });
  let transferForm = $state({
    to_manager_id: '',
    permanent: true,
    date_from: '',
    date_to: '',
    comment: ''
  });

  const canConfirm = $derived(auth.can('clients.confirm'));
  const canManage = $derived(card?.can.manage ?? false);
  const transferInProgress = $derived(
    card?.transfer !== null && card?.transfer !== undefined
      && ['pending', 'scheduled'].includes(card.transfer.status)
  );
  const canStartTransfer = $derived(canManage && !transferInProgress);

  const eventTitles: Record<string, string> = {
    registered: 'Регистрация',
    activated: 'Регистрация подтверждена',
    rejected: 'Регистрация отклонена',
    password_reset: 'Сброс пароля',
    password_changed: 'Смена пароля',
    manager_assigned: 'Назначен менеджер',
    manager_claimed: 'Менеджер взял клиента',
    manager_unassigned: 'Менеджер снят',
    manager_transfer_requested: 'Запрошена передача клиента',
    manager_transfer_accepted: 'Передача клиента принята',
    manager_transfer_started: 'Передача вступила в силу',
    manager_transfer_declined: 'Передача клиента отклонена',
    manager_transfer_cancelled: 'Передача клиента отменена',
    manager_transfer_expired: 'Временная передача завершена',
    manager_released: 'Передан в пул (без менеджера)',
    updated: 'Профиль изменён',
    profile_updated: 'Профиль изменён',
    avatar_updated: 'Фото профиля обновлено',
    avatar_removed: 'Фото профиля удалено',
    created: 'Создан администратором',
    blocked: 'Доступ заблокирован',
    unblocked: 'Доступ восстановлен',
    consent: 'Согласие на обработку данных'
  };

  const timeline = $derived(
    card
      ? [
          ...card.history.map((item) => ({
            id: `h${item.id}`,
            title: eventTitles[item.event] ?? item.event,
            actor: item.actor?.name ?? '',
            comment: item.comment,
            created_at: item.created_at
          })),
          ...(card.views ?? []).map((item) => ({
            id: `v${item.id}`,
            title: 'Открыл(а) профиль клиента',
            actor: item.user.name,
            comment: '',
            created_at: item.created_at
          }))
        ].sort((a, b) => (a.created_at < b.created_at ? 1 : -1))
      : []
  );

  const stateTitles: Record<string, string> = {
    active: 'активен',
    pending: 'ожидает подтверждения',
    rejected: 'отклонён'
  };

  const historyItems = $derived(
    timeline.slice((historyPage - 1) * historyPerPage, historyPage * historyPerPage)
  );

  onMount(() => {
    void load();
    void loadInterests();
    void loadRequests();
    void loadStatuses();
  });

  async function loadStatuses(): Promise<void> {
    try {
      const data = await requestFilters();
      statuses = data.statuses;
    } catch {
      statuses = [];
    }
  }

  async function load(silent = false): Promise<void> {
    if (!silent) {
      loading = true;
    }

    error = '';

    try {
      card = await getClientCard(id);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить клиента';
    } finally {
      loading = false;
    }
  }

  async function loadInterests(): Promise<void> {
    interestsLoading = true;

    try {
      const data = await getClientInterests(id, {
        q: interestsQuery,
        sort: interestsSort,
        page: interestsPage,
        perPage: interestsPerPage
      });

      interests = data.items;
      interestsTotal = data.total;
    } catch {
      interests = [];
      interestsTotal = 0;
    } finally {
      interestsLoading = false;
    }
  }

  function applyInterestsSearch(): void {
    interestsQuery = interestsInput.trim();
    interestsPage = 1;
    void loadInterests();
  }

  function changeInterestsSort(): void {
    interestsPage = 1;
    void loadInterests();
  }

  function changeInterestsPage(next: number): void {
    interestsPage = next;
    void loadInterests();
  }

  function changeInterestsPerPage(value: number): void {
    interestsPerPage = value;
    interestsPage = 1;
    void loadInterests();
  }

  async function loadRequests(): Promise<void> {
    requestsLoading = true;

    try {
      const data = await listRequests({
        client_id: id,
        state: requestsState || undefined,
        status: requestsStatus || undefined,
        q: requestsQuery || undefined,
        page: requestsPage,
        per_page: requestsPerPage
      });

      requestsItems = data.items;
      requestsTotal = data.total;
    } catch {
      requestsItems = [];
      requestsTotal = 0;
    } finally {
      requestsLoading = false;
    }
  }

  function openRequests(state: '' | 'open' | 'closed' | 'overdue'): void {
    requestsState = state;
    requestsStatus = '';
    requestsQuery = '';
    requestsInput = '';
    requestsPage = 1;
    tab = 'requests';
    void loadRequests();
  }

  function showTab(next: 'summary' | 'requests' | 'interests' | 'history'): void {
    tab = next;
  }

  function changeRequestsState(value: string): void {
    requestsState = value as '' | 'open' | 'closed' | 'overdue';
    requestsPage = 1;
    void loadRequests();
  }

  function changeRequestsStatus(value: string): void {
    requestsStatus = value;
    requestsPage = 1;
    void loadRequests();
  }

  function applyRequestsSearch(): void {
    requestsQuery = requestsInput.trim();
    requestsPage = 1;
    void loadRequests();
  }

  function changeRequestsPage(next: number): void {
    requestsPage = next;
    void loadRequests();
  }

  function changeRequestsPerPage(value: number): void {
    requestsPerPage = value;
    requestsPage = 1;
    void loadRequests();
  }

  function changeHistoryPage(next: number): void {
    historyPage = next;
  }

  function changeHistoryPerPage(value: number): void {
    historyPerPage = value;
    historyPage = 1;
  }

  async function runAction(action: () => Promise<unknown>, notice: string): Promise<void> {
    busy = true;
    actionError = '';
    actionNotice = '';

    try {
      await action();
      actionNotice = notice;
      await load(true);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось выполнить действие';
    } finally {
      busy = false;
    }
  }

  async function approve(): Promise<void> {
    const comment = prompt('Комментарий к подтверждению (необязательно):', '') ?? '';

    await runAction(() => activateClient(id, comment.trim()), 'Регистрация подтверждена');
  }

  async function decline(): Promise<void> {
    const comment = prompt('Причина отклонения (обязательно):', '');

    if (comment === null || comment.trim().length < 3) {
      return;
    }

    await runAction(() => rejectClient(id, comment.trim()), 'Регистрация отклонена');
  }

  async function toggleBlock(): Promise<void> {
    if (!card) {
      return;
    }

    const block = card.client.active;

    if (!confirm(block ? 'Заблокировать клиента? Он не сможет войти в кабинет.' : 'Разблокировать клиента?')) {
      return;
    }

    const comment = block ? (prompt('Причина блокировки (необязательно):', '') ?? '') : '';

    await runAction(
      () => (block ? blockClient(id, comment.trim()) : unblockClient(id, comment.trim())),
      block ? 'Клиент заблокирован' : 'Клиент разблокирован'
    );
  }

  function openEdit(): void {
    if (!card) {
      return;
    }

    editForm = {
      name: card.client.name,
      company: card.client.company,
      inn: card.client.inn,
      position: card.client.position,
      phone: card.client.phone,
      email: card.client.email
    };
    actionError = '';
    actionNotice = '';
    editOpen = true;
  }

  async function saveEdit(): Promise<void> {
    editBusy = true;
    actionError = '';

    try {
      await updateClientContacts(id, { ...editForm });
      editOpen = false;
      actionNotice = 'Контакты сохранены';
      await load(true);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось сохранить контакты';
    } finally {
      editBusy = false;
    }
  }

  function formatDateTime(value: string): string {
    return value ? value.replace('T', ' ').slice(0, 16) : '';
  }

  function formatDate(value: string | null): string {
    return value ? value.slice(0, 10).split('-').reverse().join('.') : '';
  }

  async function loadManagers(): Promise<void> {
    if (managers.length > 0) {
      return;
    }

    try {
      managers = (await listManagers()).items;
    } catch {
      managers = [];
    }
  }

  function openAssign(): void {
    managerForm = { manager_id: card?.manager ? String(card.manager.id) : '', comment: '' };
    actionError = '';
    actionNotice = '';
    managerOpen = true;
    void loadManagers();
  }

  async function saveAssign(): Promise<void> {
    managerBusy = true;
    actionError = '';

    try {
      const managerId = managerForm.manager_id === '' ? null : Number(managerForm.manager_id);
      await assignClient(id, managerId, managerForm.comment.trim());
      managerOpen = false;
      actionNotice = managerId === null ? 'Менеджер снят' : 'Менеджер назначен';
      await load(true);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось назначить менеджера';
    } finally {
      managerBusy = false;
    }
  }

  function openTransfer(): void {
    transferForm = {
      to_manager_id: '',
      permanent: true,
      date_from: todayIso(),
      date_to: '',
      comment: ''
    };
    actionError = '';
    actionNotice = '';
    transferOpen = true;
    void loadManagers();
  }

  async function saveTransfer(): Promise<void> {
    managerBusy = true;
    actionError = '';

    try {
      if (transferForm.date_from === '') {
        throw new ApiError('validation_error', 'Укажите дату передачи', 422);
      }

      if (!transferForm.permanent && transferForm.date_to === '') {
        throw new ApiError('validation_error', 'Укажите дату окончания периода', 422);
      }

      await transferClient(
        id,
        transferForm.to_manager_id === '' ? null : Number(transferForm.to_manager_id),
        transferForm.date_from,
        transferForm.permanent ? null : transferForm.date_to,
        transferForm.comment.trim()
      );
      transferOpen = false;
      actionNotice = transferForm.to_manager_id === ''
        ? 'Клиент передан в пул'
        : 'Передача отправлена сотруднику';
      await load(true);
    } catch (cause) {
      actionError = cause instanceof ApiError ? cause.message : 'Не удалось передать клиента';
    } finally {
      managerBusy = false;
    }
  }

  async function claimSelf(): Promise<void> {
    await runAction(() => claimClient(id), 'Вы взяли клиента');
  }

  async function acceptCurrentTransfer(): Promise<void> {
    if (!card?.transfer) {
      return;
    }

    await runAction(() => acceptTransfer(card!.transfer!.id), 'Передача принята');
  }

  async function declineCurrentTransfer(): Promise<void> {
    if (!card?.transfer) {
      return;
    }

    await runAction(() => declineTransfer(card!.transfer!.id), 'Передача отклонена');
  }

  async function cancelCurrentTransfer(): Promise<void> {
    if (!card?.transfer) {
      return;
    }

    await runAction(() => cancelTransfer(card!.transfer!.id), 'Передача отменена');
  }
</script>

<section class="page">
  <div class="head">
    <Button variant="ghost" onclick={() => router.navigate('/clients')}>← Клиенты</Button>

    {#if card}
      <div class="head-actions">
        {#if card.client.reg_state === 'pending'}
          {#if canConfirm}
            <Button loading={busy} onclick={() => void approve()}>Подтвердить регистрацию</Button>
            <Button variant="ghost" disabled={busy} onclick={() => void decline()}>Отклонить</Button>
          {/if}
        {:else}
          {#if card.can.claim}
            <Button loading={busy} onclick={() => void claimSelf()}>Взять себе</Button>
          {/if}
          {#if card.can.assign}
            <Button variant="ghost" disabled={busy} onclick={openAssign}>Назначить</Button>
          {/if}
          {#if canManage}
            {#if canStartTransfer}
              <Button variant="ghost" disabled={busy} onclick={openTransfer}>Передать</Button>
            {/if}
            <Button disabled={busy} onclick={openEdit}>Редактирование</Button>
          {/if}
          {#if canManage}
            {#if card.client.active}
              <Button variant="danger" disabled={busy} onclick={() => void toggleBlock()}>Заблокировать</Button>
            {:else}
              <Button variant="ghost" disabled={busy} onclick={() => void toggleBlock()}>Разблокировать</Button>
            {/if}
          {/if}
        {/if}
      </div>
    {/if}
  </div>

  {#if actionError}
    <div class="alert">{actionError}</div>
  {/if}
  {#if actionNotice}
    <div class="notice">{actionNotice}</div>
  {/if}

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else if error}
    <div class="alert">{error}</div>
  {:else if card}
    <div class="card profile">
      <div class="row">
        <div>
          <h1>{card.client.name}</h1>
          <div class="meta">
            {#if card.client.company}<span>{card.client.company}</span>{/if}
            {#if card.client.position}<span>{card.client.position}</span>{/if}
          </div>
        </div>
        <span class="state" class:on={card.client.reg_state === 'active' && card.client.active}>
          {#if card.client.reg_state === 'active' && !card.client.active}
            заблокирован
          {:else}
            {stateTitles[card.client.reg_state] ?? card.client.reg_state}
          {/if}
        </span>
      </div>

      <dl class="contacts">
        <div><dt>E-mail</dt><dd>{card.client.email || '—'}</dd></div>
        <div><dt>Телефон</dt><dd>{card.client.phone || '—'}</dd></div>
        <div><dt>ИНН</dt><dd>{card.client.inn || '—'}</dd></div>
        <div><dt>Регистрация</dt><dd>{formatDateTime(card.client.registered_at)}</dd></div>
        <div>
          <dt>Последняя активность</dt>
          <dd>{card.client.last_seen_at ? formatDateTime(card.client.last_seen_at) : '—'}</dd>
        </div>
      </dl>

      <div class="manager-row">
        <span class="manager-label">Менеджер</span>
        {#if card.manager}
          <a class="manager-name link" href={`#/users/${card.manager.id}`}>{card.manager.name}</a>
          {#if card.manager.temporary_until}
            <span class="manager-temp">временно до {formatDate(card.manager.temporary_until)}</span>
          {/if}
        {:else}
          <span class="manager-none">не назначен</span>
        {/if}
      </div>
    </div>

    {#if card.transfer && card.transfer.status !== 'active'}
      <div class="transfer" class:incoming={card.transfer.incoming}>
        <div class="transfer-text">
          <strong>Передача клиента:</strong>
          {card.transfer.from?.name ?? '—'} →
          {card.transfer.to?.name ?? 'в пул (без менеджера)'}
          {#if card.transfer.date_from}
            · с {formatDate(card.transfer.date_from)}
          {/if}
          {#if card.transfer.date_to}
            до {formatDate(card.transfer.date_to)}
          {/if}
          {#if card.transfer.comment}<span class="transfer-comment">{card.transfer.comment}</span>{/if}
          {#if card.transfer.status === 'scheduled'}
            <span class="transfer-note">вступит в силу с {formatDate(card.transfer.date_from ?? '')}</span>
          {:else if card.transfer.incoming}
            <span class="transfer-note">ожидает вашего решения</span>
          {:else}
            <span class="transfer-note">ожидает решения получателя</span>
          {/if}
        </div>
        <div class="transfer-actions">
          {#if card.transfer.can_accept}
            <Button loading={busy} onclick={() => void acceptCurrentTransfer()}>Принять</Button>
            <Button variant="ghost" disabled={busy} onclick={() => void declineCurrentTransfer()}>
              Отклонить
            </Button>
          {/if}
          {#if card.transfer.can_cancel}
            <Button variant="ghost" disabled={busy} onclick={() => void cancelCurrentTransfer()}>
              Отменить передачу
            </Button>
          {/if}
        </div>
      </div>
    {/if}

    <div class="card-tabs tab-scroll">
      <button
        type="button"
        class="card-tab"
        class:active={tab === 'summary'}
        onclick={() => showTab('summary')}
      >
        Сводная информация
      </button>
      <button
        type="button"
        class="card-tab"
        class:active={tab === 'requests'}
        onclick={() => showTab('requests')}
      >
        Заявки
      </button>
      <button
        type="button"
        class="card-tab"
        class:active={tab === 'interests'}
        onclick={() => showTab('interests')}
      >
        Целевой интерес
      </button>
      <button
        type="button"
        class="card-tab"
        class:active={tab === 'history'}
        onclick={() => showTab('history')}
      >
        История
      </button>
    </div>

    {#if tab === 'summary'}
      <div class="tiles">
      <button type="button" class="tile" onclick={() => openRequests('')}>
        <span class="value">{card.stats.total}</span><span class="label">всего заявок</span>
      </button>
      <button type="button" class="tile" onclick={() => openRequests('open')}>
        <span class="value">{card.stats.open}</span><span class="label">открыто</span>
      </button>
      <button type="button" class="tile" onclick={() => openRequests('closed')}>
        <span class="value">{card.stats.closed}</span><span class="label">закрыто</span>
      </button>
      <button type="button" class="tile" onclick={() => openRequests('overdue')}>
        <span class="value warn">{card.stats.overdue}</span><span class="label">просрочено</span>
      </button>
    </div>
    {/if}

    {#if tab === 'interests'}
      <div class="card">
        <h2>Целевой интерес к позициям</h2>
      <p class="hint">Что клиент заказывал — для будущих целевых продаж (без отменённых заявок)</p>

      <div class="block-toolbar">
        <form
          class="search"
          onsubmit={(event) => {
            event.preventDefault();
            applyInterestsSearch();
          }}
        >
          <SearchInput
            bind:value={interestsInput}
            historyKey="client-interests"
            placeholder="Поиск позиции"
            onclear={applyInterestsSearch}
            onpick={applyInterestsSearch}
          />
          <Button type="submit" variant="ghost">Найти</Button>
        </form>

        <select bind:value={interestsSort} onchange={changeInterestsSort}>
          <option value="qty_desc">Количество: по убыванию</option>
          <option value="qty_asc">Количество: по возрастанию</option>
          <option value="orders_desc">Заказов: больше</option>
          <option value="orders_asc">Заказов: меньше</option>
          <option value="last_desc">Последний заказ: новые</option>
          <option value="last_asc">Последний заказ: старые</option>
          <option value="name_asc">Название: А–Я</option>
          <option value="name_desc">Название: Я–А</option>
        </select>
      </div>

      {#if interestsLoading && interests.length === 0}
        <div class="center"><Spinner size={22} /></div>
      {:else if interests.length === 0}
        <p class="empty">{interestsQuery ? 'Ничего не найдено' : 'Позиций пока нет'}</p>
      {:else}
        <div class="interests">
          <div class="tr th">
            <span>Позиция</span>
            <span>Склад</span>
            <span class="num">Заказов</span>
            <span class="num">Количество</span>
            <span>Последний раз</span>
          </div>
          {#each interests as item (item.name + '|' + item.unit)}
            <div class="tr" use:tooltip={item.description}>
              <span class="name">{item.name}{item.unit ? ` (${item.unit})` : ''}</span>
              <span>{item.warehouse_name || '—'}</span>
              <span class="num">{item.orders}</span>
              <span class="num">{item.total_qty}</span>
              <span>{formatDateTime(item.last_at)}</span>
            </div>
          {/each}
        </div>

        <div class="pager-wrap">
          <Pagination
            page={interestsPage}
            perPage={interestsPerPage}
            total={interestsTotal}
            loading={interestsLoading}
            perPageOptions={[20, 50, 100]}
            always
            onchange={changeInterestsPage}
            onperpage={changeInterestsPerPage}
          />
        </div>
      {/if}
      </div>
    {/if}

    {#if tab === 'history'}
      <div class="card">
        <h2>История</h2>
        {#if timeline.length === 0}
          <p class="empty">Событий нет</p>
        {:else}
          <ol class="timeline">
            {#each historyItems as item (item.id)}
              <li>
                <div class="event">{item.title}</div>
                <div class="when">
                  {formatDateTime(item.created_at)}
                  {#if item.actor}· {item.actor}{/if}
                </div>
                {#if item.comment}<div class="comment">{item.comment}</div>{/if}
              </li>
            {/each}
          </ol>

          <div class="pager-wrap">
            <Pagination
              page={historyPage}
              perPage={historyPerPage}
              total={timeline.length}
              perPageOptions={[20, 50, 100]}
              always
              onchange={changeHistoryPage}
              onperpage={changeHistoryPerPage}
            />
          </div>
        {/if}
      </div>
    {/if}

    {#if tab === 'requests'}
      <div class="card">
        <h2>Заявки клиента</h2>
        <div class="block-toolbar">
        <form
          class="search"
          onsubmit={(event) => {
            event.preventDefault();
            applyRequestsSearch();
          }}
        >
          <SearchInput
            bind:value={requestsInput}
            historyKey="client-requests"
            placeholder="Номер, тема, текст"
            onclear={applyRequestsSearch}
            onpick={applyRequestsSearch}
          />
          <Button type="submit" variant="ghost">Найти</Button>
        </form>

        <select
          value={requestsState}
          onchange={(event) => changeRequestsState(event.currentTarget.value)}
        >
          <option value="">Все состояния</option>
          <option value="open">Открыто ({card.stats.open})</option>
          <option value="closed">Закрыто ({card.stats.closed})</option>
          <option value="overdue">Просрочено ({card.stats.overdue})</option>
        </select>

        <select
          value={requestsStatus}
          onchange={(event) => changeRequestsStatus(event.currentTarget.value)}
        >
          <option value="">Все статусы</option>
          {#each statuses as status (status.code)}
            <option value={status.code}>
              {status.title} ({card.stats.by_status[status.code] ?? 0})
            </option>
          {/each}
        </select>
      </div>

      {#if requestsLoading && requestsItems.length === 0}
        <div class="center"><Spinner size={22} /></div>
      {:else if requestsItems.length === 0}
        <p class="empty">Заявок нет</p>
      {:else}
        <div class="requests">
          {#each requestsItems as item (item.id)}
            <a class="request" href={`#/requests/${item.id}`}>
              <div class="line">
                <span class="number">{item.number}</span>
                <StatusBadge title={item.status.title} color={item.status.color} />
                {#if item.is_overdue}<span class="overdue">просрочена</span>{/if}
              </div>
              <div class="subject">{item.subject}</div>
              <div class="when">
                {formatDateTime(item.created_at)}
                {#if item.manager}· менеджер: {item.manager.name}{/if}
              </div>
            </a>
          {/each}
        </div>

        <div class="pager-wrap">
          <Pagination
            page={requestsPage}
            perPage={requestsPerPage}
            total={requestsTotal}
            loading={requestsLoading}
            perPageOptions={[20, 50, 100]}
            always
            onchange={changeRequestsPage}
            onperpage={changeRequestsPerPage}
          />
        </div>
      {/if}
      </div>
    {/if}

    <Modal open={editOpen} title="Редактирование клиента" onclose={() => (editOpen = false)}>
      <div class="form">
        <label class="edit-label">
          <span>Имя</span>
          <input bind:value={editForm.name} maxlength="255" />
        </label>
        <label class="edit-label">
          <span>Компания</span>
          <input bind:value={editForm.company} maxlength="255" />
        </label>
        <label class="edit-label">
          <span>ИНН</span>
          <input bind:value={editForm.inn} maxlength="12" />
        </label>
        <label class="edit-label">
          <span>Должность</span>
          <input bind:value={editForm.position} maxlength="255" />
        </label>
        <label class="edit-label">
          <span>Телефон</span>
          <input bind:value={editForm.phone} maxlength="255" />
        </label>
        <label class="edit-label">
          <span>E-mail</span>
          <input bind:value={editForm.email} maxlength="255" />
        </label>
      </div>

      {#if actionError}
        <div class="alert">{actionError}</div>
      {/if}

      <div class="modal-actions">
        <Button variant="ghost" disabled={editBusy} onclick={() => (editOpen = false)}>Отмена</Button>
        <Button loading={editBusy} onclick={() => void saveEdit()}>Сохранить</Button>
      </div>
    </Modal>

    <Modal open={managerOpen} title="Назначить менеджера" onclose={() => (managerOpen = false)}>
      <div class="form">
        <label class="edit-label">
          <span>Сотрудник</span>
          <select bind:value={managerForm.manager_id}>
            <option value="">— без менеджера —</option>
            {#each managers as manager (manager.id)}
              <option value={String(manager.id)}>
                {manager.name}{manager.level >= 50 ? ' (администратор)' : ''}
              </option>
            {/each}
          </select>
        </label>
        <label class="edit-label">
          <span>Комментарий</span>
          <input bind:value={managerForm.comment} placeholder="Например: закрепляю за собой" maxlength="500" />
        </label>
      </div>

      {#if actionError}
        <div class="alert">{actionError}</div>
      {/if}

      <div class="modal-actions">
        <Button variant="ghost" disabled={managerBusy} onclick={() => (managerOpen = false)}>Отмена</Button>
        <Button loading={managerBusy} onclick={() => void saveAssign()}>Назначить</Button>
      </div>
    </Modal>

    <Modal open={transferOpen} title="Передать клиента" onclose={() => (transferOpen = false)}>
      <div class="form">
        <label class="edit-label">
          <span>Кому</span>
          <select bind:value={transferForm.to_manager_id}>
            <option value="">— в пул (без менеджера) —</option>
            {#each managers.filter((item) => item.id !== card?.manager?.id) as manager (manager.id)}
              <option value={String(manager.id)}>{manager.name}</option>
            {/each}
          </select>
        </label>

        <label class="edit-label">
          <span>Срок</span>
          <div class="period">
            <label class="radio">
              <input type="radio" bind:group={transferForm.permanent} value={true} /> навсегда
            </label>
            <label class="radio">
              <input type="radio" bind:group={transferForm.permanent} value={false} /> на период
            </label>
          </div>
        </label>

        <label class="edit-label">
          <span>Дата передачи</span>
          <input type="date" bind:value={transferForm.date_from} min={todayIso()} />
        </label>

        <label class="edit-label">
          <span>Дата окончания</span>
          <input
            type="date"
            bind:value={transferForm.date_to}
            min={transferForm.date_from || todayIso()}
            disabled={transferForm.permanent || transferForm.to_manager_id === ''}
          />
        </label>

        <label class="edit-label">
          <span>Комментарий</span>
          <input bind:value={transferForm.comment} placeholder="Причина передачи" maxlength="500" />
        </label>
      </div>

      <p class="hint">
        {#if transferForm.to_manager_id === ''}
          Клиент вернётся в пул — любой менеджер сможет его взять.
        {:else if card?.can.assign}
          Изменение вступит в силу с даты передачи.
        {:else}
          Сотрудник получит уведомление и сможет принять или отклонить передачу; менеджер сменится с
          даты передачи.
        {/if}
      </p>

      {#if actionError}
        <div class="alert">{actionError}</div>
      {/if}

      <div class="modal-actions">
        <Button variant="ghost" disabled={managerBusy} onclick={() => (transferOpen = false)}>Отмена</Button>
        <Button loading={managerBusy} onclick={() => void saveTransfer()}>Передать</Button>
      </div>
    </Modal>
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
    gap: var(--space-3);
    flex-wrap: wrap;
  }

  .head-actions {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    margin-left: auto;
    flex-wrap: wrap;
  }

  .alert {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--danger);
    font-size: 13px;
  }

  .notice {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: #e7f5ec;
    color: #1e6b3a;
    font-size: 13px;
  }

  .hint {
    margin: 0 0 var(--space-3);
    color: var(--muted);
    font-size: 13px;
  }

  .interests {
    display: flex;
    flex-direction: column;
  }

  .block-toolbar {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
    margin-bottom: var(--space-3);
  }

  .block-toolbar .search {
    display: flex;
    gap: var(--space-2);
    flex: 1 1 auto;
    min-width: 0;
    max-width: none;
  }

  .block-toolbar select {
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    font-size: 13px;
    color: var(--text);
  }

  .card-tabs {
    display: flex;
    gap: var(--space-1);
    border-bottom: 1px solid var(--border);
    margin-bottom: var(--space-4);
  }

  .card-tab {
    padding: 10px 16px;
    border: none;
    border-bottom: 2px solid transparent;
    background: none;
    font: inherit;
    color: var(--muted);
    cursor: pointer;
    white-space: nowrap;
  }

  .card-tab:hover {
    color: var(--text);
  }

  .card-tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
    font-weight: 500;
  }

  .interests .tr {
    display: grid;
    grid-template-columns: minmax(160px, 2fr) minmax(120px, 1fr) 90px 110px 140px;
    gap: var(--space-2);
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
    align-items: center;
  }

  .interests .tr:not(.th) {
    transition: background 0.12s ease;
  }

  .interests .tr:not(.th):hover {
    background: var(--bg);
  }

  .interests .tr.th {
    color: var(--muted);
    font-size: 12px;
    border-bottom-width: 2px;
  }

  .interests .name {
    font-weight: 500;
  }

  .interests .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
  }

  .pager-wrap {
    margin-top: var(--space-3);
  }

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .edit-label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 13px;
    color: var(--muted);
  }

  .edit-label input {
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    color: var(--text);
  }

  .edit-label select {
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    color: var(--text);
  }

  .period {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-3);
    padding-top: 4px;
  }

  .radio {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    color: var(--text);
    cursor: pointer;
  }

  .manager-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    margin-top: var(--space-3);
    padding-top: var(--space-3);
    border-top: 1px solid var(--border);
    font-size: 14px;
  }

  .manager-label {
    color: var(--muted);
    font-size: 13px;
  }

  .manager-name {
    font-weight: 500;
  }

  .manager-temp {
    color: var(--muted);
    font-size: 13px;
  }

  .manager-none {
    color: var(--muted);
  }

  .transfer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    flex-wrap: wrap;
    padding: var(--space-3);
    border: 1px solid var(--border);
    border-left: 3px solid var(--muted);
    border-radius: var(--radius-md);
    background: var(--surface);
    font-size: 14px;
  }

  .transfer.incoming {
    border-left-color: var(--primary);
    background: color-mix(in srgb, var(--primary) 6%, var(--surface));
  }

  .transfer-text {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
  }

  .transfer-comment {
    color: var(--muted);
    font-size: 13px;
  }

  .transfer-note {
    color: var(--primary);
    font-size: 13px;
  }

  .transfer-actions {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-4);
  }

  h1 {
    margin: 0;
    font-size: 22px;
  }

  h2 {
    margin: 0 0 var(--space-3);
    font-size: 16px;
  }

  .card {
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
  }

  .profile {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-3);
    flex-wrap: wrap;
  }

  .meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    color: var(--muted);
    font-size: 13px;
    margin-top: 4px;
  }

  .state {
    font-size: 12px;
    padding: 3px 10px;
    border-radius: 999px;
    border: 1px solid var(--border);
    color: var(--muted);
  }

  .state.on {
    border-color: #1e6b3a;
    color: #1e6b3a;
  }

  .contacts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--space-3);
    margin: 0;
  }

  .contacts dt {
    font-size: 12px;
    color: var(--muted);
  }

  .contacts dd {
    margin: 2px 0 0;
    font-size: 14px;
  }

  .tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: var(--space-3);
  }

  .tile {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: var(--space-3);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
    transition: border-color 0.15s ease, background 0.15s ease;
  }

  .tile:hover {
    border-color: var(--primary);
  }

  .tile:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
  }

  .value {
    font-size: 22px;
    font-weight: 600;
  }

  .value.warn {
    color: #b45309;
  }

  .label {
    font-size: 12px;
    color: var(--muted);
  }

  .timeline {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .timeline li {
    border-left: 2px solid var(--border);
    padding-left: var(--space-3);
  }

  .event {
    font-weight: 500;
    font-size: 14px;
  }

  .when {
    font-size: 12px;
    color: var(--muted);
  }

  .comment {
    margin-top: 2px;
    font-size: 13px;
  }

  .requests {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .request {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: var(--space-3);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    text-decoration: none;
    color: inherit;
  }

  .request:hover {
    border-color: var(--primary);
    background: var(--bg);
  }

  .line {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .number {
    font-size: 13px;
    color: var(--muted);
  }

  .overdue {
    font-size: 12px;
    color: #b45309;
  }

  .subject {
    font-size: 14px;
    font-weight: 500;
  }

  .empty {
    color: var(--muted);
    margin: 0;
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
