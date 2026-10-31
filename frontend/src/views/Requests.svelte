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
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import FiltersModal from '../lib/components/ui/FiltersModal.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { countActive, loadFilters, saveFilters } from '../lib/filters';
  import { formatDateTime } from '../lib/format';
  import { buildPageItems } from '../lib/pagination';
  import { clampPeriod, defaultPeriod, todayIso } from '../lib/period';

  const filterDefaults = {
    status: '',
    scope: '',
    warehouse: 0,
    from: defaultPeriod().from,
    to: defaultPeriod().to,
    sort: 'created_desc',
    view: 'list',
    per_page: 20,
    q: '',
    page: 1
  };

  const filterCountDefaults = {
    status: filterDefaults.status,
    scope: filterDefaults.scope,
    warehouse: filterDefaults.warehouse,
    from: filterDefaults.from,
    to: filterDefaults.to
  };

  const perPageOptions = [20, 50, 100];

  let statuses = $state<RequestStatus[]>([]);
  let items = $state<RequestItem[]>([]);
  let total = $state(0);
  let loading = $state(true);
  let error = $state('');
  const initialFilters = loadFilters('requests', filterDefaults);
  let filters = $state({ ...initialFilters });
  let draft = $state({ ...initialFilters });
  let page = $state(typeof initialFilters.page === 'number' && initialFilters.page > 0 ? initialFilters.page : 1);
  let perPage = $state(perPageOptions.includes(Number(initialFilters.per_page)) ? Number(initialFilters.per_page) : 20);
  let jumpPage = $state<number | null>(null);
  let search = $state(String(initialFilters.q ?? ''));
  let searchInput = $state(String(initialFilters.q ?? ''));
  let searchTimer: ReturnType<typeof setTimeout> | undefined;
  let loadSeq = 0;
  let warehouses = $state<{ id: number; name: string }[]>([]);

  $effect(() => {
    const term = searchInput.trim();
    clearTimeout(searchTimer);

    if (term === search) {
      return;
    }

    searchTimer = setTimeout(() => {
      search = term;
      void load(true);
      persist();
    }, 400);

    return () => clearTimeout(searchTimer);
  });

  let viewMode = $state<'list' | 'tiles'>(initialFilters.view === 'tiles' ? 'tiles' : 'list');
  let viewOpen = $state(false);
  let viewDraft = $state<'list' | 'tiles'>('list');
  let sortOpen = $state(false);
  let sortDraft = $state<string[]>(['created_desc']);

  const sortFields = [
    { field: 'number', label: 'Номер' },
    { field: 'subject', label: 'Тема' },
    { field: 'client', label: 'Клиент', staffOnly: true },
    { field: 'manager', label: 'Менеджер' },
    { field: 'positions', label: 'Позиций' },
    { field: 'status', label: 'Статус' },
    { field: 'due', label: 'Срок' },
    { field: 'priority', label: 'Приоритет' },
    { field: 'created', label: 'Дата создания' }
  ];

  let draftsOpen = $state(false);
  let drafts = $state<RequestDraftSummary[]>([]);
  let draftsLoading = $state(false);
  let draftsError = $state('');
  let draftBusy = $state(0);
  let draftCount = $state(0);

  const isStaff = $derived(auth.level >= 10);
  let dragSortField = $state<string | null>(null);
  let dragSortOver = $state<string | null>(null);
  const sortRows = $derived.by(() => {
    const available = sortFields.filter((item) => !item.staffOnly || isStaff);
    const activeRows = sortDraft
      .map((term) => available.find((item) => term.startsWith(`${item.field}_`)))
      .filter((item): item is (typeof available)[number] => item !== undefined);
    const inactiveRows = available.filter((item) => sortDraftIndex(item.field) === 0);

    return [...activeRows, ...inactiveRows];
  });
  const activeFilterCount = $derived(countActive(filters, filterCountDefaults));
  const searchActive = $derived(search.trim() !== '');
  const pages = $derived(Math.max(1, Math.ceil(total / perPage)));
  const rangeFrom = $derived(total === 0 ? 0 : Math.min((page - 1) * perPage + 1, total));
  const rangeTo = $derived(Math.min(page * perPage, total));
  const pageItems = $derived(buildPageItems(page, pages));

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
      })
      .catch(() => {
        warehouses = [];
      });

    void refreshDraftCount();
    void load(false);
  });

  async function refreshDraftCount(): Promise<void> {
    try {
      draftCount = (await listDrafts()).items.length;
    } catch {
      draftCount = 0;
    }
  }

  function persist(): void {
    saveFilters('requests', { ...filters, view: viewMode, per_page: perPage, q: search, page });
  }

  function resetFilters(): void {
    const period = defaultPeriod();

    draft = { ...draft, status: '', scope: '', warehouse: 0, from: period.from, to: period.to };
    filters = { ...filters, status: '', scope: '', warehouse: 0, from: period.from, to: period.to };
    persist();
    void load(true);
  }

  function resetFilterDraft(): void {
    const period = defaultPeriod();

    draft = { ...draft, status: '', scope: '', warehouse: 0, from: period.from, to: period.to };
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
    persist();
    void load(true);
  }

  function openSort(): void {
    const terms = sortTerms();

    sortDraft = terms.length > 0 ? [...terms] : [filterDefaults.sort];
    sortOpen = true;
  }

  function sortTerms(): string[] {
    return filters.sort.split(',').filter((term) => term !== '');
  }

  function sortIndex(field: string): number {
    return sortTerms().findIndex((term) => term === field || term.startsWith(`${field}_`)) + 1;
  }

  function sortDraftIndex(field: string): number {
    return sortDraft.findIndex((term) => term.startsWith(`${field}_`)) + 1;
  }

  function moveSortDraft(fromField: string, toField: string): void {
    const fromIndex = sortDraft.findIndex((term) => term.startsWith(`${fromField}_`));
    const toIndex = sortDraft.findIndex((term) => term.startsWith(`${toField}_`));

    if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex) {
      return;
    }

    const next = [...sortDraft];
    const [moved] = next.splice(fromIndex, 1);
    const insertAt = fromIndex < toIndex ? toIndex - 1 : toIndex;
    next.splice(insertAt, 0, moved);
    sortDraft = next;
  }

  function startSortDrag(event: DragEvent, field: string): void {
    dragSortField = field;

    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', field);
    }
  }

  function overSortRow(event: DragEvent, field: string): void {
    if (dragSortField === null || sortDraftIndex(field) === 0) {
      return;
    }

    event.preventDefault();
    dragSortOver = field;
  }

  function dropSortRow(event: DragEvent, field: string): void {
    event.preventDefault();

    if (dragSortField !== null && sortDraftIndex(field) > 0) {
      moveSortDraft(dragSortField, field);
    }

    dragSortField = null;
    dragSortOver = null;
  }

  function endSortDrag(): void {
    dragSortField = null;
    dragSortOver = null;
  }

  function sortTerm(field: string): 'asc' | 'desc' | null {
    const terms = sortTerms();

    if (terms.includes(`${field}_asc`) || terms.includes(field)) {
      return 'asc';
    }

    if (terms.includes(`${field}_desc`)) {
      return 'desc';
    }

    return null;
  }

  function headerSort(field: string): void {
    const terms = sortTerms();
    const asc = `${field}_asc`;
    const desc = `${field}_desc`;
    const isAsc = (term: string): boolean => term === asc || term === field;
    const isDefault = terms.length === 1 && terms[0] === filterDefaults.sort;
    let next: string[];

    if (terms.some(isAsc)) {
      next = terms.map((term) => (isAsc(term) ? desc : term));
    } else if (terms.includes(desc)) {
      next = terms.filter((term) => term !== desc);
    } else if (isDefault) {
      next = [asc];
    } else {
      next = [...terms, asc];
    }

    applySortTerms(next);
  }

  function toggleSortDir(field: string, dir: 'asc' | 'desc'): void {
    const term = `${field}_${dir}`;
    const isField = (item: string): boolean => item === field || item.startsWith(`${field}_`);

    sortDraft = sortDraft.includes(term)
      ? sortDraft.filter((item) => item !== term)
      : [...sortDraft.filter((item) => !isField(item)), term];
  }

  function toggleSortDirActive(field: string, dir: 'asc' | 'desc'): boolean {
    return sortDraft.includes(`${field}_${dir}`);
  }

  function applySortTerms(terms: string[]): void {
    filters.sort = terms.length > 0 ? terms.join(',') : filterDefaults.sort;
    changeSort();
  }

  function applySort(): void {
    sortOpen = false;
    applySortTerms(sortDraft);
  }

  function changeSort(): void {
    persist();
    void load(true);
  }

  function openView(): void {
    viewDraft = viewMode;
    viewOpen = true;
  }

  function applyView(): void {
    viewMode = viewDraft;
    viewOpen = false;
    persist();
  }

  async function load(reset: boolean): Promise<void> {
    const seq = ++loadSeq;

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

      if (seq !== loadSeq) {
        return;
      }

      items = data.items;
      total = data.total;

      const maxPage = Math.max(1, Math.ceil(data.total / perPage));

      if (page > maxPage) {
        page = maxPage;
        persist();
        void load(false);
        return;
      }

      persist();
    } catch (cause) {
      if (seq === loadSeq) {
        error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить заявки';
      }
    } finally {
      if (seq === loadSeq) {
        loading = false;
      }
    }
  }

  function applyFilters(): void {
    search = searchInput.trim();
    addHistory('requests', search);
    void load(true);
  }

  async function goToPage(target: number): Promise<void> {
    if (!Number.isFinite(target)) {
      return;
    }

    const next = Math.min(Math.max(1, Math.trunc(target)), pages);

    if (next === page) {
      return;
    }

    page = next;
    await load(false);
  }

  async function submitJump(event: Event): Promise<void> {
    event.preventDefault();

    if (typeof jumpPage !== 'number' || !Number.isFinite(jumpPage)) {
      return;
    }

    await goToPage(jumpPage);
    jumpPage = null;
  }

  function changePerPage(): void {
    page = 1;
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
    } catch (cause) {
      draftsError = cause instanceof ApiError ? cause.message : 'Не удалось удалить черновик';
    } finally {
      draftBusy = 0;
    }
  }
</script>

<section class="page page-wide">
  {#snippet headCell(className: string, label: string, field: string)}
    <button
      type="button"
      class="head-cell {className}"
      class:active={sortTerm(field) !== null}
      aria-label={sortTerm(field) === 'asc'
        ? `${label}: по возрастанию`
        : sortTerm(field) === 'desc'
          ? `${label}: по убыванию`
          : `${label}: сортировать`}
      onclick={() => headerSort(field)}
    >
      <span class="head-label">{label}</span>
      {#if sortTerm(field)}
        <span class="sort-ind" aria-hidden="true">
          <Icon name={sortTerm(field) === 'desc' ? 'arrow-down' : 'arrow-up'} size={12} />
          <span class="sort-num">{sortIndex(field)}</span>
        </span>
      {/if}
    </button>
  {/snippet}

  {#if !appSettings.salesEnabled}
    <div class="notice sales-off">
      <span class="notice-icon"><Icon name="info" size={16} /></span>
      <span>
        Продажи отключены: оформление новых заявок и черновиков недоступно, остатки складов доступны
        для просмотра.
      </span>
    </div>
  {/if}

  <div class="filters">
    <div class="head">
      <h1>Заявки</h1>
    </div>

    <form class="search" onsubmit={(event) => { event.preventDefault(); applyFilters(); }}>
      <SearchInput
        bind:value={searchInput}
        historyKey="requests"
        placeholder="Поиск: тема, клиент, номер, телефон, описание"
        onclear={applyFilters}
        onpick={applyFilters}
      />
    </form>

    <div class="filter-icons">
      <button
        type="button"
        class="sort-button"
        class:active={filters.sort !== filterDefaults.sort}
        title="Сортировка"
        aria-label="Сортировка"
        onclick={openSort}
      >
        <Icon name="sort" size={16} />
      </button>

      <FiltersModal
        icon
        count={countActive(filters, filterCountDefaults)}
        onopen={() => (draft = { ...filters })}
        onapply={applyFilterDraft}
        onreset={resetFilterDraft}
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

      <button
        type="button"
        class="sort-button"
        class:active={viewMode === 'tiles'}
        title="Вид"
        aria-label="Вид: списком или плитками"
        onclick={openView}
      >
        <Icon name="view" size={16} />
      </button>

      <span class="toolbar-divider" aria-hidden="true"></span>

      <button
        type="button"
        class="sort-button"
        class:active={draftCount > 0}
        class:has-badge={draftCount > 0}
        title="Черновики заявок"
        aria-label={draftCount > 0 ? `Черновики заявок, ${draftCount}` : 'Черновики заявок'}
        onclick={() => void openDrafts()}
      >
        <Icon name="requests" size={16} />
        {#if draftCount > 0}<span class="icon-badge" aria-hidden="true">{draftCount}</span>{/if}
      </button>

      <button
        type="button"
        class="sort-button"
        title="Новая заявка"
        aria-label="Новая заявка"
        disabled={!appSettings.salesEnabled || !auth.can('requests.create')}
        onclick={() => router.navigate('/requests/new')}
      >
        <Icon name="plus" size={16} />
      </button>
    </div>
  </div>

  {#if error}
    <div class="alert" role="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span>{error}</span>
    </div>
  {/if}

  {#if loading && items.length === 0}
    <div class="center"><Spinner size={26} /></div>
  {:else if items.length === 0}
    {#if activeFilterCount > 0}
      <div class="empty">
        <p class="empty-title">Заявки не найдены</p>
        <p class="empty-hint">
          Включены фильтры — часть заявок может быть скрыта. Сбросьте фильтры, чтобы увидеть все
          заявки.
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
    <div class="levels-wrap">
      {#if viewMode === 'list'}
        <div class="levels-head" class:compact={!isStaff}>
          {@render headCell('col-number', 'Номер', 'number')}
          {@render headCell('col-subject', 'Тема', 'subject')}
          {#if isStaff}{@render headCell('col-client', 'Клиент', 'client')}{/if}
          {@render headCell('col-manager', 'Менеджер', 'manager')}
          {@render headCell('col-count', 'Позиций', 'positions')}
          {@render headCell('col-status', 'Статус', 'status')}
          {@render headCell('col-overdue', 'Просрочка', 'due')}
          {@render headCell('col-date', 'Дата', 'created')}
        </div>
      {/if}

      <div class="levels" class:tiles={viewMode === 'tiles'} class:compact={!isStaff}>
        {#each items as item (item.id)}
          <a class="card" href={`#/requests/${item.id}`}>
            <span class="cell col-number number">{item.number}</span>
            <span class="cell col-subject subject">{item.subject}</span>
            {#if isStaff}
              <span class="cell col-client">{item.client.name}</span>
            {/if}
            <span class="cell col-manager">
              <span class="cell-label">менеджер: </span>
              {#if item.manager}
                {item.manager.name}
              {:else}
                <span class="search-badge">поиск менеджера</span>
              {/if}
            </span>
            <span class="cell col-count">
              <span class="cell-label">позиций: </span>{item.items_count}
            </span>
            <span class="cell col-status">
              <span class="status-text" style={`color: ${item.status.color}`}>{item.status.title}</span>
            </span>
            <span class="cell col-overdue">
              {#if item.is_overdue}<span class="overdue">Просрочена</span>{:else}—{/if}
            </span>
            <span class="cell col-date date">{formatDateTime(item.created_at)}</span>
          </a>
        {/each}
      </div>
    </div>

    {#snippet pagerPrev()}
      <button
        type="button"
        class="page-btn"
        disabled={page <= 1}
        aria-label="Предыдущая страница"
        onclick={() => void goToPage(page - 1)}
      >
        <Icon name="arrow-left" size={14} />
      </button>
    {/snippet}
    {#snippet pagerNext()}
      <button
        type="button"
        class="page-btn"
        disabled={page >= pages}
        aria-label="Следующая страница"
        onclick={() => void goToPage(page + 1)}
      >
        <Icon name="arrow-right" size={14} />
      </button>
    {/snippet}

    <div class="pager">
      <label class="per-page">
        <span>Показывать</span>
        <select bind:value={perPage} onchange={changePerPage}>
          {#each perPageOptions as option (option)}
            <option value={option}>{option}</option>
          {/each}
        </select>
      </label>

      {#if pages > 1}
        <div class="pager-main">
          <span class="pager-total">{rangeFrom}–{rangeTo} из {total}</span>

          <div class="pager-pages" aria-label="Постраничная навигация">
            {@render pagerPrev()}

            {#each pageItems as item, index (`${item}-${index}`)}
              {#if item === '...'}
                <span class="page-ellipsis" aria-hidden="true">…</span>
              {:else}
                <button
                  type="button"
                  class="page-btn"
                  class:active={item === page}
                  aria-current={item === page ? 'page' : undefined}
                  aria-label={`Страница ${item}`}
                  onclick={() => void goToPage(Number(item))}
                >
                  {item}
                </button>
              {/if}
            {/each}

            {@render pagerNext()}
          </div>

          <div class="pager-simple" aria-label="Постраничная навигация">
            {@render pagerPrev()}
            <span class="pager-simple-count">{page} / {pages}</span>
            {@render pagerNext()}
          </div>

          <form class="pager-jump" onsubmit={submitJump}>
            <span>Перейти</span>
            <input type="number" min="1" max={pages} bind:value={jumpPage} aria-label="Номер страницы" />
          </form>
        </div>
      {/if}
    </div>
  {/if}

  <Modal open={sortOpen} title="Сортировка" onclose={() => (sortOpen = false)}>
    <p class="sort-hint">
      Можно задать несколько условий — они применяются сверху вниз. Нажмите стрелку, чтобы добавить
      условие, и повторно — чтобы убрать.
    </p>
    <div class="sort-list" role="list">
      {#each sortRows as column (column.field)}
        {@const active = sortDraftIndex(column.field) > 0}
        <div
          class="sort-row"
          class:active
          class:drag-over={dragSortOver === column.field && dragSortField !== column.field}
          role="listitem"
          ondragover={(event) => overSortRow(event, column.field)}
          ondragleave={() => {
            if (dragSortOver === column.field) {
              dragSortOver = null;
            }
          }}
          ondrop={(event) => dropSortRow(event, column.field)}
        >
          <span
            class="sort-drag"
            class:off={!active}
            draggable={active}
            title={active ? 'Перетащите, чтобы изменить порядок' : undefined}
            aria-hidden="true"
            ondragstart={(event) => startSortDrag(event, column.field)}
            ondragend={endSortDrag}
          >
            <Icon name="holder" size={14} />
          </span>
          {#if active}
            <span class="sort-order">{sortDraftIndex(column.field)}</span>
          {/if}
          <span class="sort-row-label">{column.label}</span>
          <button
            type="button"
            class="dir-btn"
            class:on={toggleSortDirActive(column.field, 'asc')}
            title="По возрастанию"
            aria-label={`${column.label}: по возрастанию`}
            onclick={() => toggleSortDir(column.field, 'asc')}
          >
            <Icon name="arrow-up" size={16} />
          </button>
          <button
            type="button"
            class="dir-btn"
            class:on={toggleSortDirActive(column.field, 'desc')}
            title="По убыванию"
            aria-label={`${column.label}: по убыванию`}
            onclick={() => toggleSortDir(column.field, 'desc')}
          >
            <Icon name="arrow-down" size={16} />
          </button>
        </div>
      {/each}
    </div>
    <div class="modal-actions">
      <Button variant="ghost" onclick={() => (sortDraft = [filterDefaults.sort])}>Сбросить</Button>
      <div class="modal-buttons">
        <Button variant="ghost" onclick={() => (sortOpen = false)}>Отмена</Button>
        <Button onclick={applySort}>Применить</Button>
      </div>
    </div>
  </Modal>

  <Modal open={viewOpen} title="Вид" onclose={() => (viewOpen = false)}>
    <div class="view-options">
      <label class="radio">
        <input type="radio" name="req-view" value="list" bind:group={viewDraft} />
        <span>Списком (строками)</span>
      </label>
      <label class="radio">
        <input type="radio" name="req-view" value="tiles" bind:group={viewDraft} />
        <span>Плитками</span>
      </label>
    </div>
    <div class="modal-actions">
      <Button variant="ghost" onclick={() => (viewOpen = false)}>Отмена</Button>
      <Button onclick={applyView}>Применить</Button>
    </div>
  </Modal>

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
    width: 100%;
    max-width: 1280px;
    margin-inline: auto;
  }

  .page:has(.levels.tiles) {
    max-width: 1600px;
  }

  .head {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-1);
  }

  h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
    color: var(--text);
  }

  .sales-off {
    margin-bottom: var(--space-3);
  }

  .filters {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-4);
    align-items: center;
    justify-content: flex-start;
  }

  .search {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    flex: 1 1 220px;
    max-width: 360px;
    min-width: 0;
  }

  .filter-icons {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2);
    margin-left: auto;
  }

  .sort-button {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
    transition: color 0.15s ease, border-color 0.15s ease;
  }

  .sort-button:hover:not(:disabled) {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .sort-button:disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }

  .sort-button.active {
    border-color: var(--primary);
    color: var(--primary);
  }

  .icon-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    border-radius: 999px;
    background: var(--primary);
    color: #fff;
    font-size: 10px;
    line-height: 16px;
    text-align: center;
  }

  .toolbar-divider {
    width: 1px;
    height: 24px;
    margin: 0 var(--space-1);
    background: var(--border-secondary);
  }

  .notice,
  .alert {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    color: var(--text);
    font-size: 14px;
  }

  .notice {
    border-color: var(--success-border);
    background: var(--success-bg);
  }

  .notice.sales-off {
    border-color: var(--warning);
    background: var(--warning-bg);
  }

  .alert {
    border-color: var(--error-border);
    background: var(--danger-bg);
  }

  .notice-icon,
  .alert-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
  }

  .notice-icon {
    color: var(--warning-text);
  }

  .alert-icon {
    color: var(--danger);
  }

  .levels-wrap {
    display: flex;
    flex-direction: column;
  }

  .levels-head {
    display: none;
  }

  .levels {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .levels.tiles {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: var(--space-3);
  }

  .card {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
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
    background: var(--fill-tertiary);
  }

  .cell {
    min-width: 0;
    font-size: 14px;
  }

  .cell-label {
    display: none;
  }

  .levels.tiles .cell-label {
    display: inline;
  }

  .levels.tiles .card {
    display: grid;
    grid-template-columns: auto auto auto minmax(0, 1fr);
    grid-template-areas:
      'number status overdue date'
      'subject subject subject subject'
      'client manager count count';
    align-items: center;
    gap: var(--space-1) var(--space-3);
  }

  .levels.tiles.compact .card {
    grid-template-areas:
      'number status overdue date'
      'subject subject subject subject'
      'manager count count count';
  }

  .levels.tiles .col-number {
    grid-area: number;
  }

  .levels.tiles .col-status {
    grid-area: status;
    display: flex;
    align-items: center;
    gap: var(--space-1);
  }

  .levels.tiles .col-date {
    grid-area: date;
    justify-self: end;
    margin-left: auto;
  }

  .levels.tiles .col-overdue {
    grid-area: overdue;
    justify-self: end;
  }

  .levels.tiles .col-subject {
    grid-area: subject;
    margin-top: var(--space-1);
  }

  .levels.tiles .col-client {
    grid-area: client;
  }

  .levels.tiles .col-manager {
    grid-area: manager;
    color: var(--muted);
    font-size: 13px;
  }

  .levels.tiles .col-count {
    grid-area: count;
    justify-self: end;
    color: var(--muted);
    font-size: 13px;
  }

  @media (max-width: 559.98px) {
    .levels.tiles .card {
      grid-template-columns: minmax(0, 1fr) auto;
      grid-template-areas:
        'number date'
        'subject subject'
        'status overdue'
        'client manager'
        'count count';
    }

    .levels.tiles.compact .card {
      grid-template-columns: minmax(0, 1fr) auto;
      grid-template-areas:
        'number date'
        'subject subject'
        'status overdue'
        'manager manager'
        'count count';
    }
  }

  @media (min-width: 1200px) {
    .levels-head {
      position: sticky;
      top: 0;
      z-index: 2;
      display: grid;
      grid-template-columns: 110px minmax(0, 1.6fr) minmax(0, 1fr) 150px 80px 180px 120px 150px;
      align-items: stretch;
      padding: 0;
      background: var(--fill-tertiary);
      border: 1px solid var(--border-secondary);
      border-radius: var(--radius-md) var(--radius-md) 0 0;
      font-size: 14px;
      font-weight: 600;
      color: var(--text);
    }

    .levels-head.compact {
      grid-template-columns: 110px minmax(0, 1.6fr) 150px 80px 180px 120px 150px;
    }

    .head-cell {
      position: relative;
      display: flex;
      align-items: center;
      gap: 4px;
      padding: 12px var(--space-4);
      min-width: 0;
      border: none;
      background: none;
      font: inherit;
      color: inherit;
      text-align: left;
      cursor: pointer;
    }

    .head-cell:hover {
      color: var(--primary);
    }

    .head-cell.active {
      color: var(--primary);
    }

    .head-label {
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .sort-ind {
      display: inline-flex;
      align-items: center;
      gap: 2px;
      flex: 0 0 auto;
    }

    .sort-num {
      min-width: 15px;
      height: 15px;
      padding: 0 4px;
      border-radius: 999px;
      background: color-mix(in srgb, var(--primary) 14%, white);
      font-size: 10px;
      line-height: 15px;
      text-align: center;
    }

    .head-cell:not(:last-child)::after {
      content: '';
      position: absolute;
      top: 50%;
      right: 0;
      width: 1px;
      height: 1.6em;
      background: var(--border-secondary);
      transform: translateY(-50%);
    }

    .levels-head .col-count,
    .levels-head .col-overdue,
    .levels-head .col-date {
      justify-content: flex-end;
      text-align: right;
    }

    .levels:not(.tiles) {
      gap: 0;
      background: var(--surface);
      border: 1px solid var(--border-secondary);
      border-radius: 0 0 var(--radius-md) var(--radius-md);
      overflow: hidden;
    }

    .levels:not(.tiles) .card {
      display: grid;
      grid-template-columns: 110px minmax(0, 1.6fr) minmax(0, 1fr) 150px 80px 180px 120px 150px;
      align-items: center;
      gap: var(--space-3);
      padding: 12px var(--space-4);
      border: none;
      border-bottom: 1px solid var(--border-secondary);
      border-radius: 0;
      box-shadow: none;
    }

    .levels:not(.tiles).compact .card {
      grid-template-columns: 110px minmax(0, 1.6fr) 150px 80px 180px 120px 150px;
    }

    .levels:not(.tiles) .card:last-child {
      border-bottom: none;
    }

    .levels:not(.tiles) .col-count,
    .levels:not(.tiles) .col-date,
    .levels:not(.tiles) .col-overdue {
      text-align: right;
    }

    .levels:not(.tiles) .col-status {
      display: flex;
      align-items: center;
      gap: var(--space-1);
      flex-wrap: wrap;
      overflow: hidden;
    }

    .levels:not(.tiles) .col-subject {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .levels:not(.tiles) .col-client,
    .levels:not(.tiles) .col-manager {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      color: var(--muted);
    }

    .levels:not(.tiles) {
      border-top: none;
    }
  }

  @media (max-width: 1199.98px) {
    .levels:not(.tiles) .cell-label {
      display: inline;
    }

    .levels:not(.tiles) .card {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      grid-template-areas:
        'number date'
        'subject subject'
        'status overdue'
        'manager count';
      align-items: baseline;
      gap: 4px var(--space-3);
    }

    .levels:not(.tiles) .col-number {
      grid-area: number;
    }

    .levels:not(.tiles) .col-date {
      grid-area: date;
      justify-self: end;
    }

    .levels:not(.tiles) .col-subject {
      grid-area: subject;
      white-space: normal;
    }

    .levels:not(.tiles) .col-status {
      grid-area: status;
      display: flex;
      align-items: center;
      gap: var(--space-1);
      flex-wrap: wrap;
      min-width: 0;
    }

    .levels:not(.tiles) .col-overdue {
      grid-area: overdue;
      justify-self: end;
    }

    .levels:not(.tiles) .col-manager {
      grid-area: manager;
      color: var(--muted);
      font-size: 13px;
    }

    .levels:not(.tiles) .col-count {
      grid-area: count;
      justify-self: end;
      color: var(--muted);
    }

    .levels:not(.tiles) .col-client {
      display: none;
    }
  }

  .status-text {
    font-size: 13px;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
  }

  .date {
    font-size: 13px;
    color: var(--muted);
    white-space: nowrap;
  }

  .number {
    font-size: 13px;
    color: var(--muted);
    font-weight: 600;
    white-space: nowrap;
  }

  .subject {
    font-size: 15px;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .overdue {
    font-size: 12px;
    color: var(--danger);
    background: var(--danger-bg);
    border-radius: 999px;
    padding: 2px 10px;
  }

  .search-badge {
    color: var(--warning-text);
    font-weight: 500;
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

  .sort-hint {
    margin: 0 0 var(--space-2);
    font-size: 13px;
    color: var(--text-description);
  }

  .sort-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    max-height: 340px;
    overflow-y: auto;
  }

  .sort-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: 6px 0;
  }

  .sort-row.active .sort-row-label {
    color: var(--text);
    font-weight: 600;
  }

  .sort-row.drag-over {
    box-shadow: 0 -2px 0 var(--primary);
  }

  .sort-drag {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 18px;
    height: 24px;
    color: var(--text-description);
    cursor: grab;
  }

  .sort-drag:active {
    cursor: grabbing;
  }

  .sort-drag.off {
    visibility: hidden;
    cursor: default;
  }

  .sort-row-label {
    flex: 1 1 auto;
    min-width: 0;
    color: var(--text-description);
  }

  .sort-order {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    flex: 0 0 auto;
    border-radius: 999px;
    background: var(--primary);
    color: #fff;
    font-size: 12px;
  }

  .dir-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text-description);
    cursor: pointer;
  }

  .dir-btn.on {
    border-color: var(--primary);
    color: var(--primary);
  }

  .view-options {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    max-height: 340px;
    overflow-y: auto;
  }

  .radio {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: var(--text);
    cursor: pointer;
  }

  .pager {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: var(--space-3);
    font-size: 14px;
    color: var(--text);
  }

  .per-page {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .per-page select {
    padding: 4px 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    font-size: 14px;
  }

  .pager-main {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-3);
    margin-left: auto;
  }

  .pager-total {
    color: var(--text);
    white-space: nowrap;
  }

  .pager-pages {
    display: inline-flex;
    align-items: center;
    gap: var(--space-1);
  }

  .page-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 32px;
    height: 32px;
    padding: 0 6px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: 14px;
    line-height: 1;
    cursor: pointer;
  }

  .page-btn:hover:not(:disabled) {
    border-color: var(--primary);
    color: var(--primary);
  }

  .page-btn.active {
    border-color: var(--primary);
    color: var(--primary);
  }

  .page-btn:disabled {
    color: rgba(0, 0, 0, 0.25);
    cursor: not-allowed;
  }

  .page-ellipsis {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 32px;
    height: 32px;
    color: var(--text-description);
  }

  .pager-jump {
    display: inline-flex;
    align-items: center;
    gap: var(--space-1);
    white-space: nowrap;
  }

  .pager-jump input {
    width: 56px;
    height: 32px;
    padding: 0 8px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    font-size: 13px;
  }

  .pager-simple {
    display: none;
    align-items: center;
    gap: var(--space-2);
  }

  .pager-simple-count {
    color: var(--text);
  }

  @media (max-width: 575.98px) {
    .pager-pages,
    .pager-jump,
    .pager-total {
      display: none;
    }

    .pager-simple {
      display: inline-flex;
    }
  }
</style>
