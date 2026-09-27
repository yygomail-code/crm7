<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    listRequests,
    listStatuses,
    requestFilters
  } from '../lib/api/requests';
  import { listDrafts, deleteDraft, type RequestDraftSummary } from '../lib/api/drafts';
  import type { RequestItem, RequestStatus } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { router } from '../lib/router.svelte';
  import { addHistory } from '../lib/search-history';
  import StatusBadge from '../lib/components/StatusBadge.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import FiltersModal from '../lib/components/ui/FiltersModal.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import Pagination from '../lib/components/ui/Pagination.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { clearFilters, countActive, loadFilters, saveFilters } from '../lib/filters';
  import { formatDateTime } from '../lib/format';
  import { clampPeriod, defaultPeriod, todayIso } from '../lib/period';

  const filterDefaults = {
    status: '',
    scope: '',
    warehouse: 0,
    from: defaultPeriod().from,
    to: defaultPeriod().to,
    sort: 'created_desc'
  };

  const filterCountDefaults = {
    status: filterDefaults.status,
    scope: filterDefaults.scope,
    warehouse: filterDefaults.warehouse,
    from: filterDefaults.from,
    to: filterDefaults.to
  };

  let statuses = $state<RequestStatus[]>([]);
  let items = $state<RequestItem[]>([]);
  let total = $state(0);
  let page = $state(1);
  let loading = $state(true);
  let error = $state('');
  const initialFilters = loadFilters('requests', filterDefaults);
  let filters = $state({ ...initialFilters });
  let draft = $state({ ...initialFilters });
  let search = $state('');
  let searchInput = $state('');
  let warehouses = $state<{ id: number; name: string }[]>([]);
  let sorts = $state<{ code: string; title: string }[]>([]);

  let notice = $state('');

  let draftsOpen = $state(false);
  let drafts = $state<RequestDraftSummary[]>([]);
  let draftsLoading = $state(false);
  let draftsError = $state('');
  let draftBusy = $state(0);
  let draftCount = $state(0);

  const isStaff = $derived(auth.level >= 10);
  const activeFilterCount = $derived(countActive(filters, filterCountDefaults));
  const searchActive = $derived(search.trim() !== '');

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

    void refreshDraftCount();
    void load(true);
  });

  async function refreshDraftCount(): Promise<void> {
    try {
      draftCount = (await listDrafts()).items.length;
    } catch {
      draftCount = 0;
    }
  }

  function resetFilters(): void {
    const period = defaultPeriod();
    const next = { ...filterDefaults, from: period.from, to: period.to };

    draft = { ...next };
    filters = { ...next };
    clearFilters('requests');
    void load(true);
  }

  function resetFiltersAndSearch(): void {
    search = '';
    searchInput = '';
    resetFilters();
  }

  function applyFilterDraft(): void {
    const period = clampPeriod(draft.from, draft.to);

    draft.from = period.from;
    draft.to = period.to;
    filters = { ...draft };
    saveFilters('requests', filters);
    void load(true);
  }

  function changeSort(): void {
    saveFilters('requests', filters);
    void load(true);
  }

  let perPage = $state(20);

  async function load(reset: boolean): Promise<void> {
    if (reset) {
      page = 1;
    }

    loading = true;
    error = '';

    try {
      const data = await listRequests({
        status: filters.status,
        manager_scope: filters.scope || undefined,
        q: search,
        warehouse_id: filters.warehouse > 0 ? filters.warehouse : undefined,
        from: filters.from,
        to: filters.to,
        sort: filters.sort,
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

  function changePerPage(value: number): void {
    perPage = value;
    void load(true);
  }

  async function loadDrafts(): Promise<void> {
    draftsLoading = true;
    draftsError = '';

    try {
      drafts = (await listDrafts()).items;
      draftCount = drafts.length;
    } catch (cause) {
      draftsError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить черновики';
    } finally {
      draftsLoading = false;
    }
  }

  async function openDrafts(): Promise<void> {
    draftsOpen = true;

    await loadDrafts();
  }

  function openDraft(id: number): void {
    draftsOpen = false;
    router.navigate(`/requests/new?draft=${id}`);
  }

  async function removeDraft(id: number): Promise<void> {
    if (!confirm('Удалить черновик?')) {
      return;
    }

    draftBusy = id;
    draftsError = '';

    try {
      await deleteDraft(id);
      await loadDrafts();
    } catch (cause) {      draftsError = cause instanceof ApiError ? cause.message : 'Не удалось удалить черновик';
    } finally {
      draftBusy = 0;
    }
  }
</script>

<section class="page page-wide">
  <div class="head">
    <h1>Заявки</h1>
    <div class="head-actions">
      <span class="drafts-wrap" class:has-drafts={draftCount > 0}>
        <Button variant="ghost" onclick={() => void openDrafts()}>Черновики заявок</Button>
      </span>
      <Button
        disabled={!appSettings.salesEnabled}
        onclick={() => router.navigate('/requests/new')}
      >
        Новая заявка
      </Button>
    </div>
  </div>

  {#if !appSettings.salesEnabled}
    <div class="notice sales-off">
      Продажи отключены: оформление новых заявок и черновиков недоступно, остатки складов доступны
      для просмотра.
    </div>
  {/if}

  <div class="search-row">
    <form class="search" onsubmit={(event) => { event.preventDefault(); applyFilters(); }}>
      <SearchInput
        bind:value={searchInput}
        historyKey="requests"
        placeholder="Поиск: тема, клиент, номер, телефон, описание"
        onclear={applyFilters}
        onpick={applyFilters}
      />
      <Button type="submit" variant="ghost">Найти</Button>
    </form>

    <FiltersModal
      count={countActive(filters, filterCountDefaults)}
      onopen={() => (draft = { ...filters })}
      onapply={applyFilterDraft}
      onreset={resetFilters}
    >
      <label class="filter-field">
        <span>Статус</span>
        <select bind:value={draft.status}>
          <option value="">Все статусы</option>
          {#each statuses as status}
            <option value={status.code}>{status.title}</option>
          {/each}
        </select>
      </label>

      {#if isStaff}
        <label class="filter-field">
          <span>Менеджер</span>
          <select bind:value={draft.scope}>
            <option value="">Все заявки</option>
            <option value="none">Без менеджера</option>
            <option value="mine">В работе у меня</option>
            <option value="others">В работе у других менеджеров</option>
          </select>
        </label>
      {/if}

      {#if warehouses.length > 0}
        <label class="filter-field">
          <span>Склад</span>
          <select bind:value={draft.warehouse}>
            <option value={0}>Все склады</option>
            {#each warehouses as warehouse (`${warehouse.id}|${warehouse.name}`)}
              <option value={warehouse.id}>{warehouse.name}</option>
            {/each}
          </select>
        </label>
      {/if}

      <label class="filter-field">
        <span>Период с</span>
        <input type="date" bind:value={draft.from} max={draft.to} />
      </label>
      <label class="filter-field">
        <span>Период по</span>
        <input type="date" bind:value={draft.to} min={draft.from} max={todayIso()} />
      </label>
    </FiltersModal>

    <label class="sort-field">
      <span>Сортировка</span>
      <select bind:value={filters.sort} onchange={changeSort}>
        {#each sorts as sort (sort.code)}
          <option value={sort.code}>{sort.title}</option>
        {/each}
      </select>
    </label>
  </div>

  {#if error}
    <div class="alert">{error}</div>
  {/if}
  {#if notice}
    <div class="notice">{notice}</div>
  {/if}

  {#if loading && items.length === 0}
    <div class="center"><Spinner size={26} /></div>
  {:else if items.length === 0}
    {#if activeFilterCount > 0}
      <div class="empty">
        <p class="empty-title">Заявки не найдены</p>
        <p class="empty-hint">
          Включены фильтры — часть заявок может быть скрыта. Отключите фильтры, чтобы не пропустить их.
        </p>
        <Button variant="ghost" onclick={resetFiltersAndSearch}>Сбросить фильтры</Button>
      </div>
    {:else if searchActive}
      <div class="empty">
        <p class="empty-title">Заявки не найдены</p>
        <p class="empty-hint">Уточните запрос или очистите поиск.</p>
        <Button variant="ghost" onclick={resetFiltersAndSearch}>Очистить поиск</Button>
      </div>
    {:else}
      <div class="empty">Заявок пока нет</div>
    {/if}
  {:else}
    <div class="list">
      {#each items as item (item.id)}
        <div class="row">
          <a class="card" href={`#/requests/${item.id}`}>
            <div class="card-head">
              <span class="number">{item.number}</span>
              <StatusBadge title={item.status.title} color={item.status.color} />
              <span class="date">{formatDateTime(item.created_at)}</span>
            </div>
            <div class="subject">{item.subject}</div>
            <div class="meta">
              {#if isStaff}
                <span>{item.client.name}</span>
              {/if}
              {#if item.manager}
                <span>менеджер: {item.manager.name}</span>
              {:else}
                <span class="search-badge">поиск менеджера</span>
              {/if}
              {#if item.items_count > 0}
                <span>позиций: {item.items_count}</span>
              {/if}
            </div>
            {#if item.is_overdue}
              <div class="overdue">Просрочена</div>
            {/if}
          </a>
        </div>
      {/each}
    </div>

    <Pagination
      page={page}
      perPage={perPage}
      total={total}
      loading={loading}
      perPageOptions={[20, 50, 100]}
      onchange={goToPage}
      onperpage={changePerPage}
    />
  {/if}

  <Modal open={draftsOpen} title="Черновики заявок" onclose={() => (draftsOpen = false)}>
    {#if draftsLoading}
      <div class="center"><Spinner size={22} /></div>
    {:else if drafts.length === 0}
      <p class="muted">Черновиков нет</p>
    {:else}
      <div class="draft-list">
        {#each drafts as draft (draft.id)}
          <div class="draft-row">
            <button
              type="button"
              class="draft-main"
              title="Открыть черновик"
              onclick={() => openDraft(draft.id)}
            >
              <span class="draft-name">{draft.subject || 'Без темы'}</span>
              <span class="draft-meta">
                позиций: {draft.items_count} · обновлён {formatDateTime(draft.updated_at)}
              </span>
            </button>
            <button
              type="button"
              class="draft-del"
              aria-label={`Удалить черновик: ${draft.subject || 'Без темы'}`}
              disabled={draftBusy === draft.id}
              onclick={() => void removeDraft(draft.id)}
            >
              ✕
            </button>
          </div>
        {/each}
      </div>
    {/if}

    {#if draftsError}
      <div class="alert">{draftsError}</div>
    {/if}

    <div class="modal-actions">
      <div class="modal-buttons">
        <Button variant="ghost" onclick={() => (draftsOpen = false)}>Закрыть</Button>
      </div>
    </div>
  </Modal>
</section>

<style>
  .muted {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
  }

  .draft-list {
    display: flex;
    flex-direction: column;
    margin-bottom: var(--space-3);
  }

  .draft-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
  }

  .draft-main {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 0;
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    text-align: left;
    cursor: pointer;
  }

  .draft-main:hover .draft-name {
    color: var(--primary);
  }

  .draft-name {
    font-size: 14px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .draft-meta {
    font-size: 12px;
    color: var(--muted);
  }

  .draft-del {
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

  .draft-del:hover:not(:disabled) {
    border-color: var(--danger);
    color: var(--danger);
  }

  .draft-del:disabled {
    opacity: 0.55;
    cursor: not-allowed;
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

  @media (max-width: 720px) {
    .head {
      flex-direction: column;
      align-items: stretch;
    }

    .head-actions {
      width: 100%;
    }

    .head-actions :global(button) {
      flex: 1;
    }
  }

  h1 {
    margin: 0;
    font-size: 22px;
  }

  .head-actions {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
    align-items: center;
  }

  .drafts-wrap {
    display: inline-flex;
  }

  .drafts-wrap.has-drafts :global(button) {
    border-color: var(--primary);
    color: var(--primary);
    box-shadow: inset 0 0 0 1px var(--primary);
  }

  .sales-off {
    margin-bottom: var(--space-3);
  }


  select {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
  }

  .search-row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    align-items: center;
  }

  .search {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    flex: 1 1 260px;
    min-width: 0;
  }

  .sort-field {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    margin-left: var(--space-2);
    padding-left: var(--space-4);
    border-left: 1px solid var(--border);
    font-size: 14px;
    color: var(--muted);
    white-space: nowrap;
  }

  @media (max-width: 720px) {
    .search {
      flex: 1 1 100%;
    }
  }

  .row {
    display: flex;
    align-items: stretch;
    gap: var(--space-2);
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
    gap: var(--space-2);
  }

  .card {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    flex: 1;
    min-width: 0;
    padding: var(--space-3) var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    text-decoration: none;
    color: inherit;
  }

  .card:hover {
    border-color: var(--primary);
    background: var(--bg);
  }

  .card-head {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .date {
    margin-left: auto;
    font-size: 13px;
    color: var(--muted);
  }

  .number {
    font-size: 13px;
    color: var(--muted);
    font-weight: 600;
  }

  .subject {
    font-size: 15px;
    font-weight: 500;
  }

  .meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2) var(--space-3);
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

  .search-badge {
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

  .empty-title {
    margin: 0;
    color: var(--text);
    font-weight: 600;
  }

  .empty-hint {
    margin: var(--space-2) 0 var(--space-4);
    max-width: 44ch;
    margin-inline: auto;
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }

</style>
