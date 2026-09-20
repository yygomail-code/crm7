<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    bulkRequests,
    deleteSavedFilter,
    listManagers,
    listRequests,
    listSavedFilters,
    listStatuses,
    requestFilters,
    saveSavedFilter,
    type SavedFilter
  } from '../lib/api/requests';
  import type { ManagerItem, RequestItem, RequestStatus } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import { router } from '../lib/router.svelte';
  import { addHistory } from '../lib/search-history';
  import StatusBadge from '../lib/components/StatusBadge.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Pagination from '../lib/components/ui/Pagination.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { formatDateTime } from '../lib/format';

  let statuses = $state<RequestStatus[]>([]);
  let items = $state<RequestItem[]>([]);
  let total = $state(0);
  let page = $state(1);
  let loading = $state(true);
  let error = $state('');
  let statusFilter = $state('');
  let search = $state('');
  let searchInput = $state('');
  let warehouseFilter = $state(0);
  let periodFrom = $state('');
  let periodTo = $state('');
  let sortFilter = $state('created_desc');
  let warehouses = $state<{ id: number; name: string }[]>([]);
  let sorts = $state<{ code: string; title: string }[]>([]);

  let saved = $state<SavedFilter[]>([]);
  let selected = $state<number[]>([]);
  let managers = $state<ManagerItem[]>([]);
  let bulkStatus = $state('');
  let bulkManager = $state(0);
  let bulkBusy = $state(false);
  let bulkNotice = $state('');

  const canAssign = $derived(auth.can('requests.assign') || auth.can('clients.assign'));
  const canBulk = $derived(auth.can('requests.transition') || canAssign);

  onMount(() => {
    void listStatuses()
      .then((data) => {
        statuses = data;
      })
      .catch(() => {
        statuses = [];
      });

    void requestFilters()
      .then((data) => {
        warehouses = data.warehouses;
        sorts = data.sorts;
      })
      .catch(() => {
        warehouses = [];
        sorts = [];
      });

    void listSavedFilters()
      .then((data) => {
        saved = data.items;
      })
      .catch(() => {
        saved = [];
      });

    if (canAssign) {
      void listManagers()
        .then((data) => {
          managers = data.items;
        })
        .catch(() => {
          managers = [];
        });
    }

    void load(true);
  });

  function toggleSelect(id: number): void {
    selected = selected.includes(id) ? selected.filter((value) => value !== id) : [...selected, id];
  }

  function clearSelection(): void {
    selected = [];
    bulkStatus = '';
    bulkManager = 0;
  }

  function resetFilters(): void {
    statusFilter = '';
    warehouseFilter = 0;
    periodFrom = '';
    periodTo = '';
    sortFilter = 'created_desc';
    searchInput = '';
    search = '';
    void load(true);
  }

  async function applySavedFilter(filter: SavedFilter): Promise<void> {
    statusFilter = filter.params.status ?? '';
    warehouseFilter = filter.params.warehouse_id ?? 0;
    periodFrom = filter.params.from ?? '';
    periodTo = filter.params.to ?? '';
    sortFilter = filter.params.sort ?? 'created_desc';
    searchInput = filter.params.q ?? '';
    search = searchInput;
    await load(true);
  }

  async function storeFilter(): Promise<void> {
    const name = prompt('Название фильтра:', '');

    if (name === null || name.trim() === '') {
      return;
    }

    try {
      const created = await saveSavedFilter(name.trim(), {
        status: statusFilter,
        q: search,
        warehouse_id: warehouseFilter > 0 ? warehouseFilter : undefined,
        from: periodFrom,
        to: periodTo,
        sort: sortFilter
      });
      saved = [created, ...saved];
      bulkNotice = 'Фильтр сохранён';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить фильтр';
    }
  }

  async function removeSavedFilter(filter: SavedFilter): Promise<void> {
    try {
      await deleteSavedFilter(filter.id);
      saved = saved.filter((item) => item.id !== filter.id);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось удалить фильтр';
    }
  }

  async function bulkTransition(): Promise<void> {
    if (selected.length === 0 || bulkStatus === '') {
      return;
    }

    const comment = prompt('Комментарий к смене статуса (обязателен):', '');

    if (comment === null || comment.trim().length < 3) {
      return;
    }

    bulkBusy = true;
    error = '';
    bulkNotice = '';

    try {
      const result = await bulkRequests({
        ids: selected,
        action: 'transition',
        to_status: bulkStatus,
        comment: comment.trim()
      });

      bulkNotice = `Обновлено: ${result.updated}${result.failed > 0 ? `, не удалось: ${result.failed}` : ''}`;
      clearSelection();
      await load(true);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Массовое действие не выполнено';
    } finally {
      bulkBusy = false;
    }
  }

  async function bulkAssign(): Promise<void> {
    if (selected.length === 0 || bulkManager === 0) {
      return;
    }

    bulkBusy = true;
    error = '';
    bulkNotice = '';

    try {
      const result = await bulkRequests({
        ids: selected,
        action: 'assign',
        manager_id: bulkManager,
        comment: 'Массовое назначение'
      });

      bulkNotice = `Назначено: ${result.updated}${result.failed > 0 ? `, не удалось: ${result.failed}` : ''}`;
      clearSelection();
      await load(true);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Массовое назначение не выполнено';
    } finally {
      bulkBusy = false;
    }
  }

  const perPage = 20;

  async function load(reset: boolean): Promise<void> {
    if (reset) {
      page = 1;
      selected = [];
    }

    loading = true;
    error = '';

    try {
      const data = await listRequests({
        status: statusFilter,
        q: search,
        warehouse_id: warehouseFilter > 0 ? warehouseFilter : undefined,
        from: periodFrom,
        to: periodTo,
        sort: sortFilter,
        page,
        per_page: perPage
      });

      items = data.items;
      total = data.total;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить заявки';
    } finally {
      loading = false;
    }
  }

  function applyFilters(): void {
    search = searchInput.trim();
    addHistory('requests', search);
    void load(true);
  }

  function goToPage(next: number): void {
    page = next;
    void load(false);
  }
</script>

<section class="page page-wide">
  <div class="head">
    <h1>Заявки</h1>
    <div class="head-actions">
      <Button onclick={() => router.navigate('/requests/new')}>Новая заявка</Button>
    </div>
  </div>

  <div class="filters">
    <select bind:value={statusFilter} onchange={() => void load(true)}>
      <option value="">Все статусы</option>
      {#each statuses as status}
        <option value={status.code}>{status.title}</option>
      {/each}
    </select>

    {#if warehouses.length > 0}
      <select bind:value={warehouseFilter} onchange={() => void load(true)}>
        <option value={0}>Все склады</option>
        {#each warehouses as warehouse (warehouse.id)}
          <option value={warehouse.id}>{warehouse.name}</option>
        {/each}
      </select>
    {/if}

    <label class="period">
      <span>с</span>
      <input type="date" bind:value={periodFrom} onchange={() => void load(true)} />
    </label>
    <label class="period">
      <span>по</span>
      <input type="date" bind:value={periodTo} onchange={() => void load(true)} />
    </label>

    <select bind:value={sortFilter} onchange={() => void load(true)}>
      {#each sorts as sort (sort.code)}
        <option value={sort.code}>{sort.title}</option>
      {/each}
    </select>

    <form class="search" onsubmit={(event) => { event.preventDefault(); applyFilters(); }}>
      <SearchInput
        bind:value={searchInput}
        historyKey="requests"
        placeholder="Поиск: номер, тема, текст"
        onclear={applyFilters}
        onpick={applyFilters}
      />
      <Button type="submit" variant="ghost">Найти</Button>
    </form>

    <Button variant="ghost" onclick={resetFilters}>Сбросить</Button>
  </div>

  <div class="saved">
    {#each saved as filter (filter.id)}
      <span class="chip">
        <button type="button" class="chip-apply" onclick={() => void applySavedFilter(filter)}>
          {filter.name}
        </button>
        <button
          type="button"
          class="chip-remove"
          aria-label="Удалить фильтр"
          onclick={() => void removeSavedFilter(filter)}
        >
          ×
        </button>
      </span>
    {/each}
    <Button variant="ghost" onclick={() => void storeFilter()}>Сохранить фильтр</Button>
  </div>

  {#if canBulk && selected.length > 0}
    <div class="bulk">
      <span class="bulk-count">Выбрано: {selected.length}</span>

      <select bind:value={bulkStatus}>
        <option value="">Статус…</option>
        {#each statuses as status}
          <option value={status.code}>{status.title}</option>
        {/each}
      </select>
      <Button variant="ghost" loading={bulkBusy} disabled={bulkStatus === ''} onclick={() => void bulkTransition()}>
        Сменить статус
      </Button>

      {#if canAssign}
        <select bind:value={bulkManager}>
          <option value={0}>Менеджер…</option>
          {#each managers as manager (manager.id)}
            <option value={manager.id}>{manager.name}</option>
          {/each}
        </select>
        <Button variant="ghost" loading={bulkBusy} disabled={bulkManager === 0} onclick={() => void bulkAssign()}>
          Назначить
        </Button>
      {/if}

      <Button variant="ghost" onclick={clearSelection}>Снять выбор</Button>
    </div>
  {/if}

  {#if error}
    <div class="alert">{error}</div>
  {/if}
  {#if bulkNotice}
    <div class="notice">{bulkNotice}</div>
  {/if}

  {#if loading && items.length === 0}
    <div class="center"><Spinner size={26} /></div>
  {:else if items.length === 0}
    <div class="empty">Заявок пока нет</div>
  {:else}
    <div class="list">
      {#each items as item (item.id)}
        <div class="row" class:picked={selected.includes(item.id)}>
          {#if canBulk}
            <div class="pick">
              <input
                type="checkbox"
                aria-label={`Выбрать заявку ${item.number}`}
                checked={selected.includes(item.id)}
                onchange={() => toggleSelect(item.id)}
              />
            </div>
          {/if}
          <a class="card" href={`#/requests/${item.id}`}>
            <div class="card-head">
              <span class="number">{item.number}</span>
              <StatusBadge title={item.status.title} color={item.status.color} />
            </div>
            <div class="subject">{item.subject}</div>
            <div class="meta">
              <span>{item.client.name}</span>
              {#if item.manager}
                <span>менеджер: {item.manager.name}</span>
              {:else}
                <span class="search">поиск менеджера</span>
              {/if}
              <span>{formatDateTime(item.created_at)}</span>
            </div>
            {#if item.is_overdue}
              <div class="overdue">Просрочена</div>
            {/if}
          </a>
        </div>
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
  }

  h1 {
    margin: 0;
    font-size: 22px;
  }

  .head-actions {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .filters {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    align-items: center;
  }

  .period {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--muted);
  }

  .period input {
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    font-size: 13px;
  }

  select {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
  }

  .search {
    display: flex;
    gap: var(--space-2);
    flex: 1;
    min-width: 220px;
  }

  .saved {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    align-items: center;
  }

  .chip {
    display: inline-flex;
    align-items: center;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    overflow: hidden;
  }

  .chip-apply {
    border: none;
    background: none;
    padding: 6px 4px 6px 12px;
    font: inherit;
    font-size: 13px;
    color: var(--muted);
    cursor: pointer;
  }

  .chip-apply:hover {
    color: var(--primary);
  }

  .chip-remove {
    border: none;
    background: none;
    padding: 6px 10px 6px 4px;
    font-size: 14px;
    line-height: 1;
    color: var(--muted);
    cursor: pointer;
  }

  .chip-remove:hover {
    color: var(--danger);
  }

  .bulk {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-3);
    background: color-mix(in srgb, var(--primary) 6%, white);
    border: 1px solid color-mix(in srgb, var(--primary) 30%, var(--border));
    border-radius: var(--radius-md);
  }

  .bulk-count {
    font-size: 13px;
    font-weight: 500;
  }

  .row {
    display: flex;
    align-items: stretch;
    gap: var(--space-2);
  }

  .row.picked .card {
    border-color: var(--primary);
  }

  .pick {
    display: flex;
    align-items: center;
    padding: 0 var(--space-1, 4px);
  }

  .notice {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: #e7f5ec;
    color: #1e6b3a;
    font-size: 13px;
  }

  .list {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .card {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    flex: 1;
    min-width: 0;
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    text-decoration: none;
    color: inherit;
  }

  .card:hover {
    border-color: var(--primary);
  }

  .card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .number {
    font-size: 13px;
    color: var(--muted);
    font-weight: 600;
  }

  .subject {
    font-size: 16px;
    font-weight: 500;
  }

  .meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    font-size: 13px;
    color: var(--muted);
  }

  .overdue {
    align-self: flex-start;
    font-size: 12px;
    color: var(--danger);
    background: var(--danger-bg);
    border-radius: 999px;
    padding: 2px 10px;
  }

  .search {
    color: #b45309;
    font-weight: 500;
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
