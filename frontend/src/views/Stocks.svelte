<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    emailLevels,
    exportLevels,
    importHistory,
    importLevels,
    listLevels,
    listWarehouses,
    searchCounts,
    type ImportPriceType,
    type StockFilters
  } from '../lib/api/stocks';
  import type {
    StockImportJob,
    StockLevel,
    StockUpdate,
    StockWarehouse,
    ItemGroup
  } from '../lib/api/types';
  import { listItemGroups } from '../lib/api/groups';
  import Button from '../lib/components/ui/Button.svelte';
  import ExportModal from '../lib/components/ui/ExportModal.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import PhotoGallery from '../lib/components/stocks/PhotoGallery.svelte';
  import { cart } from '../lib/stores/cart.svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { addHistory } from '../lib/search-history';
  import { loadFilters, saveFilters } from '../lib/filters';
  import { router } from '../lib/router.svelte';
  import { config } from '../lib/config';
  import { formatDate, formatDateTime, formatPrice, stockQuantityText } from '../lib/format';

  let warehouses = $state<StockWarehouse[]>([]);
  let levels = $state<StockLevel[]>([]);
  let jobs = $state<StockImportJob[]>([]);
  let updates = $state<StockUpdate[]>([]);
  let detailOpen = $state(false);
  let detailTarget = $state<StockLevel | null>(null);
  let selectedIds = $state<number[]>([]);
  const stockFilterDefaults = {
    sort: 'name_asc',
    group_ids: null as number[] | null,
    group_no_group: true,
    per_page: 20,
    view: 'list',
    photos: true,
    query: '',
    page: 1,
    active: 'all'
  };
  const initialStockFilters = loadFilters('stocks', stockFilterDefaults);

  let query = $state(String(initialStockFilters.query));
  let searchInput = $state(String(initialStockFilters.query));
  let page = $state<number>(typeof initialStockFilters.page === 'number' ? initialStockFilters.page : 1);
  let total = $state(0);
  let jumpPage = $state<number | null>(null);
  let loading = $state(true);
  let loadingLevels = $state(false);
  let error = $state('');
  let message = $state('');
  let canImport = $state(false);
  let canEdit = $state(false);
  let busy = $state(false);
  let file = $state<File | null>(null);
  let actualDate = $state(new Date().toISOString().slice(0, 10));
  let importOpen = $state(false);
  let importError = $state('');
  let importTab = $state<'import' | 'history'>('import');
  let importMappingColumns = $state<string[]>([]);
  let importMappingTypes = $state<ImportPriceType[]>([]);
  let importMapping = $state<Record<string, number>>({});
  let exportOpen = $state(false);
  let counts = $state<Record<string, number>>({});

  let groups = $state<ItemGroup[]>([]);

  const warehousesEnabled = $derived(appSettings.warehousesEnabled);
  const sortFields = $derived(
    warehousesEnabled
      ? [
          { field: 'name', label: 'Наименование' },
          { field: 'qty', label: 'Остаток на складе' },
          { field: 'warehouse', label: 'Склад' },
          { field: 'price', label: 'Цена' }
        ]
      : [
          { field: 'name', label: 'Наименование' },
          { field: 'qty', label: 'Остаток' },
          { field: 'price', label: 'Цена' }
        ]
  );
  const groupsPreselected =
    Array.isArray(initialStockFilters.group_ids) && initialStockFilters.group_ids.length > 0;
  let stockSort = $state(initialStockFilters.sort);
  let stockGroups = $state<number[]>(parseGroups(initialStockFilters.group_ids));
  let stockNoGroup = $state<boolean>(initialStockFilters.group_no_group !== false);
  let stockActive = $state<string>(
    ['active', 'inactive'].includes(String(initialStockFilters.active))
      ? String(initialStockFilters.active)
      : 'all'
  );
  const canDeactivate = $derived(auth.can('stocks.deactivate'));
  let perPage = $state<number>(typeof initialStockFilters.per_page === 'number' ? initialStockFilters.per_page : 20);
  let viewMode = $state<'list' | 'tiles'>(initialStockFilters.view === 'tiles' ? 'tiles' : 'list');
  let showPhotos = $state<boolean>(initialStockFilters.photos !== false);
  let viewOpen = $state(false);
  let viewDraft = $state<'list' | 'tiles'>('list');
  let photoDraft = $state<boolean>(true);
  let sortOpen = $state(false);
  let sortDraft = $state<string[]>(parseSort(initialStockFilters.sort));
  let groupOpen = $state(false);
  let groupDraft = $state<number[]>([]);
  let groupNoGroupDraft = $state<boolean>(true);
  let warehouseOpen = $state(false);
  let warehouseDraft = $state<number[]>([]);
  let helpOpen = $state(false);
  let searchTimer: ReturnType<typeof setTimeout> | undefined;

  const canCreate = $derived(auth.can('requests.create') && appSettings.cartEnabled);
  const pages = $derived(perPage <= 0 ? 1 : Math.max(1, Math.ceil(total / perPage)));
  const rangeFrom = $derived(perPage <= 0 ? (total === 0 ? 0 : 1) : Math.min((page - 1) * perPage + 1, total));
  const rangeTo = $derived(perPage <= 0 ? total : Math.min(page * perPage, total));
  const pageItems = $derived(buildPageItems(page, pages));
  const pricesEnabled = $derived(appSettings.pricesEnabled);
  const groupsEnabled = $derived(appSettings.groupsEnabled);
  const listVars = $derived(
    `--photo: ${showPhotos ? '88px' : '0px'}; --price: ${pricesEnabled ? '100px' : '0px'}; --cart: ${canCreate ? '120px' : '0px'};`
  );

  $effect(() => {
    if (groupsEnabled && groups.length === 0) {
      void loadGroups();
    }
  });

  $effect(() => {
    const term = searchInput.trim();
    clearTimeout(searchTimer);

    if (term === query) {
      return;
    }

    searchTimer = setTimeout(() => {
      void runSearch(false);
    }, 400);

    return () => clearTimeout(searchTimer);
  });

  async function loadGroups(): Promise<void> {
    try {
      const data = await listItemGroups();
      groups = data.items;
    } catch {
      groups = [];
    }

    if (!groupsPreselected && stockGroups.length === 0) {
      stockGroups = groups.map((item) => item.id);
    }
  }

  const groupFilterActive = $derived.by(() => {
    if (!groupsEnabled) {
      return false;
    }

    const allGroups = groups.length > 0 && stockGroups.length === groups.length;
    const noneSelected = stockGroups.length === 0 && !stockNoGroup;

    return !(allGroups && stockNoGroup) && !noneSelected;
  });

  const filters = $derived.by((): StockFilters => {
    const result: StockFilters = {
      q: query,
      sort: stockSort,
      show_zero: appSettings.allowZeroStock
    };

    if (canDeactivate && stockActive !== 'all') {
      result.active = stockActive;
    }

    if (groupsEnabled && groupFilterActive) {
      result.group_ids = stockGroups;
      result.no_group = stockNoGroup;
    }

    return result;
  });

  const hasFilters = $derived(query !== '');
  const selectedWarehouses = $derived(warehouses.filter((item) => selectedIds.includes(item.id)));
  const headDate = $derived(
    selectedWarehouses.length === 1 ? selectedWarehouses[0].actual_date : null
  );

  function openDetail(level: StockLevel): void {
    detailTarget = level;
    detailOpen = true;
  }

  function addToCart(level: StockLevel): void {
    const warehouse = warehouses.find((item) => item.id === level.warehouse_id);

    cart.add({
      warehouseId: level.warehouse_id,
      warehouseName: level.warehouse_name || warehouse?.name || '',
      name: level.name,
      unit: level.unit,
      quantity: 1,
      stockLevelId: level.id,
      price: level.price ?? null
    });
  }

  function inCart(level: StockLevel): number {
    return cart.quantityOf(level.warehouse_id, level.name);
  }

  onMount(() => {
    void init();

    const timer = setInterval(() => void loadLevels(true), config.listPollMs);

    return () => clearInterval(timer);
  });

  async function init(): Promise<void> {
    loading = true;
    error = '';

    try {
      const data = await listWarehouses();
      warehouses = data.items;
      canImport = data.can_import;
      canEdit = data.can_edit;

      if (warehouses.length > 0) {
        await setWarehouses(warehouses.map((item) => item.id));
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить склады';
    } finally {
      loading = false;
    }
  }

  async function setWarehouses(ids: number[]): Promise<void> {
    selectedIds = [...ids].sort((a, b) => a - b);
    page = 1;
    query = searchInput.trim();
    void refreshCounts();
    await loadLevels();
  }

  async function refreshCounts(): Promise<void> {
    if (!hasFilters) {
      counts = {};
      return;
    }

    try {
      const data = await searchCounts(filters);
      counts = data.counts;
    } catch {
      counts = {};
    }
  }

  async function applyFilters(): Promise<void> {
    page = 1;
    void refreshCounts();
    await loadLevels();
  }

  function cartCount(warehouseId: number): number {
    return cart.items.filter((item) => item.warehouseId === warehouseId).length;
  }

  function parseGroups(value: unknown): number[] {
    if (Array.isArray(value)) {
      return value.map((item) => Number(item)).filter((item) => item > 0);
    }

    const single = Number(value);

    return single > 0 ? [single] : [];
  }

  function parseSort(value: string): string[] {
    const parts = value
      .split(',')
      .map((part) => (part.trim() === 'warehouse' ? 'warehouse_asc' : part.trim()))
      .filter((part) => part !== '');

    return parts.length > 0 ? parts : ['name_asc'];
  }

  function sortOrderIndex(field: string): number {
    return sortDraft.findIndex((value) => value.startsWith(`${field}_`)) + 1;
  }

  function toggleSortDir(field: string, dir: 'asc' | 'desc'): void {
    const value = `${field}_${dir}`;
    const other = `${field}_${dir === 'asc' ? 'desc' : 'asc'}`;
    const otherIndex = sortDraft.indexOf(other);

    if (sortDraft.includes(value)) {
      sortDraft = sortDraft.filter((item) => item !== value);
      return;
    }

    if (otherIndex >= 0) {
      const next = [...sortDraft];
      next[otherIndex] = value;
      sortDraft = next;
      return;
    }

    sortDraft = [...sortDraft, value];
  }

  function openSort(): void {
    sortDraft = parseSort(stockSort);
    sortOpen = true;
  }

  function applySort(): void {
    sortOpen = false;

    const next = sortDraft.length > 0 ? sortDraft.join(',') : 'name_asc';

    if (next === stockSort) {
      return;
    }

    stockSort = next;
    changeSort();
  }

  function sortTerm(field: string): 'asc' | 'desc' | null {
    const terms = parseSort(stockSort);

    if (terms.includes(`${field}_asc`)) {
      return 'asc';
    }

    if (terms.includes(`${field}_desc`)) {
      return 'desc';
    }

    return null;
  }

  function sortIndex(field: string): number {
    return parseSort(stockSort).findIndex((term) => term.startsWith(`${field}_`)) + 1;
  }

  function headerSort(field: string): void {
    const terms = parseSort(stockSort);
    const asc = `${field}_asc`;
    const desc = `${field}_desc`;

    let next: string[];

    if (terms.includes(asc)) {
      next = terms.map((term) => (term === asc ? desc : term));
    } else if (terms.includes(desc)) {
      next = terms.filter((term) => term !== desc);
    } else {
      next = [...terms, asc];
    }

    const value = next.length > 0 ? next.join(',') : 'name_asc';

    if (value === stockSort) {
      return;
    }

    stockSort = value;
    changeSort();
  }

  function saveStockFilters(): void {
    saveFilters('stocks', {
      sort: stockSort,
      group_ids: stockGroups,
      group_no_group: stockNoGroup,
      per_page: perPage,
      view: viewMode,
      photos: showPhotos,
      query,
      page,
      active: stockActive
    });
  }

  function openView(): void {
    viewDraft = viewMode;
    photoDraft = showPhotos;
    viewOpen = true;
  }

  function applyView(): void {
    viewOpen = false;

    if (viewDraft === viewMode && photoDraft === showPhotos) {
      return;
    }

    viewMode = viewDraft;
    showPhotos = photoDraft;
    saveStockFilters();
  }

  function changeSort(): void {
    saveStockFilters();
    void applyFilters();
  }

  function setStatus(value: string): void {
    if (stockActive === value) {
      return;
    }

    stockActive = value;
    saveStockFilters();
    void applyFilters();
  }

  function openGroup(): void {
    groupDraft = [...stockGroups];
    groupNoGroupDraft = stockNoGroup;
    groupOpen = true;
  }

  function toggleAllGroups(): void {
    groupDraft = groupDraft.length === groups.length ? [] : groups.map((item) => item.id);
  }

  function applyGroup(): void {
    groupOpen = false;

    const next = [...groupDraft].sort((a, b) => a - b);

    if (
      next.length === stockGroups.length &&
      next.every((id, index) => id === stockGroups[index]) &&
      groupNoGroupDraft === stockNoGroup
    ) {
      return;
    }

    stockGroups = next;
    stockNoGroup = groupNoGroupDraft;
    changeGroup();
  }

  function changeGroup(): void {
    saveStockFilters();
    void applyFilters();
  }

  function openWarehouse(): void {
    warehouseDraft = [...selectedIds];
    warehouseOpen = true;
  }

  function toggleAllWarehouses(): void {
    warehouseDraft = warehouseDraft.length === warehouses.length
      ? []
      : warehouses.map((item) => item.id);
  }

  function applyWarehouse(): void {
    warehouseOpen = false;

    const next = [...warehouseDraft].sort((a, b) => a - b);

    if (next.length === selectedIds.length && next.every((id, index) => id === selectedIds[index])) {
      return;
    }

    void setWarehouses(next);
  }

  async function loadLevels(silent = false): Promise<void> {
    if (selectedIds.length === 0) {
      levels = [];
      total = 0;
      return;
    }

    if (!silent) {
      loadingLevels = true;
      error = '';
    }

    try {
      const data = await listLevels(selectedIds, filters, page, perPage);
      levels = data.items;
      total = data.total;
    } catch (cause) {
      if (!silent) {
        error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить остатки';
      }
    } finally {
      if (!silent) {
        loadingLevels = false;
      }
    }
  }

  async function runSearch(record: boolean): Promise<void> {
    query = searchInput.trim();

    if (record && query !== '') {
      addHistory('stocks', query);
    }

    await applyFilters();
    saveStockFilters();
  }

  async function search(): Promise<void> {
    clearTimeout(searchTimer);
    await runSearch(true);
  }

  function buildPageItems(current: number, count: number): (number | '...')[] {
    if (count <= 7) {
      return Array.from({ length: count }, (_, index) => index + 1);
    }

    const items: (number | '...')[] = [];

    if (current <= 3) {
      for (let index = 1; index <= 5; index += 1) {
        items.push(index);
      }

      items.push('...');
      items.push(count);

      return items;
    }

    if (current >= count - 2) {
      items.push(1);
      items.push('...');

      for (let index = count - 4; index <= count; index += 1) {
        items.push(index);
      }

      return items;
    }

    items.push(1);
    items.push('...');

    for (let index = current - 1; index <= current + 1; index += 1) {
      items.push(index);
    }

    items.push('...');
    items.push(count);

    return items;
  }

  async function goToPage(target: number): Promise<void> {
    if (perPage <= 0) {
      return;
    }

    const next = Math.min(Math.max(1, Math.trunc(target)), pages);

    if (next === page) {
      return;
    }

    page = next;
    saveStockFilters();
    await loadLevels();
  }

  async function submitJump(event: Event): Promise<void> {
    event.preventDefault();

    if (jumpPage === null) {
      return;
    }

    await goToPage(jumpPage);
    jumpPage = null;
  }

  function changePerPage(): void {
    page = 1;
    saveStockFilters();
    void loadLevels();
  }

  async function doExport(format: string, byEmail: boolean): Promise<void> {
    exportOpen = false;

    if (selectedIds.length === 0) {
      error = 'Выберите хотя бы один склад';
      return;
    }

    busy = true;
    error = '';
    message = '';

    try {
      if (byEmail) {
        const result = await emailLevels(selectedIds, filters, format);
        message = `Остатки отправлены на ${result.email}`;
      } else {
        await exportLevels(selectedIds, filters, format);
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось выгрузить остатки';
    } finally {
      busy = false;
    }
  }

  async function doImport(mapping?: Record<string, number>): Promise<void> {
    if (file === null) {
      importError = 'Выберите файл (xls, xlsx или csv)';
      return;
    }

    busy = true;
    importError = '';
    message = '';

    try {
      const result = await importLevels(file, actualDate, mapping);

      if (result.status === 'needs_mapping') {
        importMappingColumns = result.columns;
        importMappingTypes = result.types;
        importMapping = Object.fromEntries(result.columns.map((column) => [column, 0]));
        return;
      }

      message =
        `Импорт: загружено ${result.rows_imported} строк` +
        (result.rows_created > 0 ? `, новых позиций ${result.rows_created}` : '') +
        (result.warehouses_created > 0 ? `, новых складов ${result.warehouses_created}` : '') +
        (result.rows_zeroed > 0 ? `, обнулено ${result.rows_zeroed}` : '') +
        (result.rows_skipped > 0 ? `, пропущено ${result.rows_skipped}` : '') +
        (result.prices_imported > 0 ? `, цен ${result.prices_imported}` : '');

      if (result.errors.length > 0) {
        message += `. ${result.errors.slice(0, 3).join('; ')}`;
      }

      file = null;
      importOpen = false;
      importMappingColumns = [];
      importMapping = {};
      await init();
      await loadHistory();
    } catch (cause) {
      importError = cause instanceof ApiError ? cause.message : 'Не удалось импортировать файл';
    } finally {
      busy = false;
    }
  }

  async function loadHistory(): Promise<void> {
    try {
      const data = await importHistory();
      jobs = data.jobs;
      updates = data.updates;
    } catch {
      // некритично
    }
  }

  function selectImportTab(tab: 'import' | 'history'): void {
    importTab = tab;

    if (tab === 'history') {
      void loadHistory();
    }
  }

  function openImport(tab: 'import' | 'history' = 'import'): void {
    selectImportTab(tab);
    importMappingColumns = [];
    importMapping = {};
    importOpen = true;
  }

  function onFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    file = input.files?.[0] ?? null;
  }

  function openItemPage(level: StockLevel): void {
    detailOpen = false;
    router.navigate(`/stocks/items/${level.id}/edit`);
  }

</script>

<section class="page page-wide">
  <div class="head">
    <h1>Номенклатура</h1>
    {#if headDate}
      <p class="head-subtitle">Остатки на {formatDate(headDate)}</p>
    {/if}
  </div>

  {#if error}
    <div class="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span>{error}</span>
    </div>
  {/if}
  {#if message}
    <div class="notice">
      <span class="notice-icon"><Icon name="check-circle" size={16} /></span>
      <span>{message}</span>
    </div>
  {/if}

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else if warehouses.length === 0}
    <div class="empty">Нет доступных складов</div>
  {:else}
    <div class="filters">
      <form
        class="search"
        onsubmit={(event) => {
          event.preventDefault();
          void search();
        }}
      >
        <SearchInput
          bind:value={searchInput}
          historyKey="stocks"
          placeholder="Поиск позиции"
          onclear={() => void search()}
          onpick={() => void search()}
        />
      </form>

      <div class="filter-icons">
        <button
          type="button"
          class="sort-button"
          class:active={stockSort !== stockFilterDefaults.sort}
          title="Сортировка"
          aria-label="Сортировка"
          onclick={openSort}
        >
            <Icon name="sort" size={16} />
        </button>

        {#if warehousesEnabled}
          <button
            type="button"
            class="sort-button"
            title="Склад"
            aria-label="Выбрать склад"
            onclick={openWarehouse}
          >
            <Icon name="stocks" size={16} />
          </button>
        {/if}

        {#if groupsEnabled}
          <button
            type="button"
            class="sort-button"
            class:active={groupFilterActive}
            title="Группа"
            aria-label="Группа"
            onclick={openGroup}
          >
            <Icon name="group" size={16} />
          </button>
        {/if}

        <button
          type="button"
          class="sort-button"
          class:active={viewMode === 'tiles'}
          title="Отображение"
          aria-label="Отображение: списком или плитками"
          onclick={openView}
        >
          <Icon name="view" size={16} />
        </button>

        <span class="toolbar-divider" aria-hidden="true"></span>

        {#if canEdit}
          <button
            type="button"
            class="sort-button"
            title="Добавить позицию"
            aria-label="Добавить позицию"
            onclick={() => router.navigate('/stocks/items/new')}
          >
            <Icon name="plus" size={16} />
          </button>
        {/if}

        {#if canImport}
          <button
            type="button"
            class="sort-button"
            title="Импорт остатков из 1С"
            aria-label="Импорт остатков из 1С"
            onclick={() => openImport('import')}
          >
            <Icon name="import" size={16} />
          </button>
        {/if}

        <button
          type="button"
          class="sort-button"
          title="Экспорт"
          aria-label="Экспорт остатков"
          disabled={busy || selectedIds.length === 0}
          onclick={() => (exportOpen = true)}
        >
          <Icon name="export" size={16} />
        </button>

        <span class="toolbar-divider" aria-hidden="true"></span>

        <button
          type="button"
          class="sort-button"
          title="Как пользоваться"
          aria-label="Как пользоваться страницей"
          onclick={() => (helpOpen = true)}
        >
          <Icon name="help" size={16} />
        </button>
      </div>
    </div>

    <Modal
      open={importOpen}
      title="Импорт остатков из 1С"
      bodyMinHeight={372}
      onclose={() => (importOpen = false)}
    >
      <div class="tabs" role="tablist">
        <button
          type="button"
          role="tab"
          class="tab"
          class:active={importTab === 'import'}
          aria-selected={importTab === 'import'}
          onclick={() => selectImportTab('import')}
        >
          Импорт
        </button>
        <button
          type="button"
          role="tab"
          class="tab"
          class:active={importTab === 'history'}
          aria-selected={importTab === 'history'}
          onclick={() => selectImportTab('history')}
        >
          История
        </button>
      </div>

      {#if importTab === 'import'}
        {#if importMappingColumns.length > 0}
          <p class="import-hint">
            В файле есть типы цен, которых нет у нас. Укажите, как распределить цены — выбор запомнится и
            применится в следующий раз автоматически.
          </p>
          {#each importMappingColumns as column (column)}
            <div class="import-field">
              <span class="import-label">{column}</span>
              <select bind:value={importMapping[column]}>
                <option value={0}>Не импортировать</option>
                {#each importMappingTypes as type (type.id)}
                  <option value={type.id}>{type.title}</option>
                {/each}
              </select>
            </div>
          {/each}
          {#if importError}
            <div class="alert">
              <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
              <span>{importError}</span>
            </div>
          {/if}
          <div class="modal-actions">
            <Button variant="ghost" onclick={() => (importMappingColumns = [])}>Назад</Button>
            <Button loading={busy} onclick={() => void doImport(importMapping)}>Продолжить импорт</Button>
          </div>
        {:else}
          <p class="import-hint">
            Файл Excel (xls/xlsx) в формате 1С: строки «Склад …» и далее «товар; ед.; остаток на складе».
            CSV: те же три колонки либо «склад; товар; ед.; остаток на складе». Импорт заменяет остатки целиком:
            позиции, которых нет в файле, обнуляются и скрываются, пока в настройках системы не разрешены
            нулевые остатки. Новые склады из файла добавляются автоматически.
          </p>
          <div class="import-field">
            <label class="import-label" for="import-file">Файл</label>
            <input id="import-file" type="file" accept=".xls,.xlsx,.csv,.txt" onchange={onFileChange} />
          </div>
          <div class="import-field">
            <label class="import-label" for="import-date">Дата остатков</label>
            <input id="import-date" type="date" bind:value={actualDate} />
          </div>
          {#if importError}
            <div class="alert">
              <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
              <span>{importError}</span>
            </div>
          {/if}
          <div class="modal-actions">
            <Button variant="ghost" onclick={() => (importOpen = false)}>Отмена</Button>
            <Button loading={busy} disabled={file === null} onclick={() => void doImport()}>Загрузить</Button>
          </div>
        {/if}
      {:else if jobs.length === 0}
        <p class="empty">Импортов ещё не было</p>
      {:else}
        <div class="history">
          {#each jobs as job (job.id)}
            <div class="job">
              <div class="row">
                <span class="file">{job.file_name}</span>
                <span class="status" class:failed={job.status === 'failed'}>
                  {job.status === 'done' ? 'загружен' : job.status === 'failed' ? 'ошибка' : 'в работе'}
                </span>
              </div>
              <div class="job-meta">
                <span>{formatDateTime(job.created_at)}</span>
                <span>{job.user}</span>
                <span>строк: {job.rows_imported} из {job.rows_total}</span>
                {#if job.rows_skipped > 0}<span class="warn">пропущено: {job.rows_skipped}</span>{/if}
                <span>на дату {job.actual_date}</span>
              </div>
              {#if job.errors}<div class="errors">{job.errors}</div>{/if}
            </div>
          {/each}
        </div>
      {/if}
    </Modal>

    <Modal open={sortOpen} title="Сортировка" onclose={() => (sortOpen = false)}>
      <p class="sort-hint">
        Можно задать несколько условий — они применяются сверху вниз. Нажмите стрелку,
        чтобы добавить условие, и повторно — чтобы убрать.
      </p>

      {#if canDeactivate}
        <div class="sort-status">
          <span class="sort-status-label">Показывать:</span>
          <div class="status-opts">
            {#each [['all', 'Все'], ['active', 'Активные'], ['inactive', 'Неактивные']] as [value, label] (value)}
              <button
                type="button"
                class="status-opt"
                class:on={stockActive === value}
                onclick={() => setStatus(value)}
              >
                {label}
              </button>
            {/each}
          </div>
        </div>
      {/if}

      <div class="sort-list">
        {#each sortFields as item (item.field)}
          {#if item.field !== 'price' || pricesEnabled}
            <div class="sort-row" class:active={sortOrderIndex(item.field) > 0}>
              {#if sortOrderIndex(item.field) > 0}
                <span class="sort-order">{sortOrderIndex(item.field)}</span>
              {/if}
              <span class="sort-row-label">{item.label}</span>
              <button
                type="button"
                class="dir-btn"
                class:on={sortDraft.includes(`${item.field}_asc`)}
                title="По возрастанию"
                aria-label={`${item.label}: по возрастанию`}
                onclick={() => toggleSortDir(item.field, 'asc')}
              >
                <Icon name="arrow-up" size={16} />
              </button>
              <button
                type="button"
                class="dir-btn"
                class:on={sortDraft.includes(`${item.field}_desc`)}
                title="По убыванию"
                aria-label={`${item.label}: по убыванию`}
                onclick={() => toggleSortDir(item.field, 'desc')}
              >
                <Icon name="arrow-down" size={16} />
              </button>
            </div>
          {/if}
        {/each}
      </div>
      <div class="modal-actions">
        <Button variant="ghost" onclick={() => (sortDraft = ['name_asc'])}>Сбросить</Button>
        <Button variant="ghost" onclick={() => (sortOpen = false)}>Отмена</Button>
        <Button onclick={applySort}>Применить</Button>
      </div>
    </Modal>

    <Modal open={groupOpen} title="Группы позиций" onclose={() => (groupOpen = false)}>
      <div class="sort-options">
        <label class="sort-option">
          <input
            type="checkbox"
            checked={groupDraft.length === groups.length && groups.length > 0}
            onchange={toggleAllGroups}
          />
          <span>Все группы</span>
        </label>

        <label class="sort-option">
          <input type="checkbox" bind:checked={groupNoGroupDraft} />
          <span>Без группы</span>
        </label>

        {#each groups as group (group.id)}
          <label class="sort-option">
            <input type="checkbox" value={group.id} bind:group={groupDraft} />
            <span>{group.title}</span>
          </label>
        {/each}
      </div>
      <div class="modal-actions">
        <Button variant="ghost" onclick={() => (groupOpen = false)}>Отмена</Button>
        <Button onclick={applyGroup}>Применить</Button>
      </div>
    </Modal>

    <Modal open={warehouseOpen} title="Склады" onclose={() => (warehouseOpen = false)}>
      <div class="sort-options">
        <label class="sort-option">
          <input
            type="checkbox"
            checked={warehouseDraft.length === warehouses.length && warehouses.length > 0}
            onchange={toggleAllWarehouses}
          />
          <span>Все склады</span>
        </label>

        {#each warehouses as warehouse (warehouse.id)}
          <label class="sort-option warehouse-option">
            <input type="checkbox" value={warehouse.id} bind:group={warehouseDraft} />
            <span class="warehouse-option-name">{warehouse.name}</span>
            {#if warehouse.is_personal}<span class="personal" title="Персональный доступ">•</span>{/if}
            <span
              class="count"
              class:found={hasFilters && (counts[warehouse.id] ?? 0) > 0}
              class:zero={hasFilters && (counts[warehouse.id] ?? 0) === 0}
            >
              {hasFilters ? (counts[warehouse.id] ?? 0) : warehouse.positions}
            </span>
            {#if cartCount(warehouse.id) > 0}
              <span class="picked" title="Позиций в корзине заявки">в заявке: {cartCount(warehouse.id)}</span>
            {/if}
          </label>
        {/each}
      </div>
      <div class="modal-actions">
        <Button variant="ghost" onclick={() => (warehouseOpen = false)}>Отмена</Button>
        <Button onclick={applyWarehouse}>Применить</Button>
      </div>
    </Modal>

    <Modal open={viewOpen} title="Отображение" onclose={() => (viewOpen = false)}>
      <div class="view-columns">
        <div class="view-column">
          <p class="sort-hint">Вид</p>
          <div class="sort-options">
            <label class="sort-option">
              <input type="radio" name="stock-view" value="list" bind:group={viewDraft} />
              <span>Списком</span>
            </label>
            <label class="sort-option">
              <input type="radio" name="stock-view" value="tiles" bind:group={viewDraft} />
              <span>Плитками</span>
            </label>
          </div>
        </div>

        <div class="view-column">
          <p class="sort-hint">Фото в позициях</p>
          <div class="sort-options">
            <label class="sort-option">
              <input type="radio" name="stock-photos" value={true} bind:group={photoDraft} />
              <span>С фото</span>
            </label>
            <label class="sort-option">
              <input type="radio" name="stock-photos" value={false} bind:group={photoDraft} />
              <span>Без фото</span>
            </label>
          </div>
        </div>
      </div>

      <div class="modal-actions">
        <Button variant="ghost" onclick={() => (viewOpen = false)}>Отмена</Button>
        <Button onclick={applyView}>Применить</Button>
      </div>
    </Modal>

    <Modal
      open={detailOpen}
      label={detailTarget?.name ?? 'Позиция'}
      wide
      closeButton={false}
      onclose={() => (detailOpen = false)}
    >
      {#if detailTarget}
        {@const target = detailTarget}
        {@const detailCartQty = inCart(target)}
        <div class="detail">
          <div class="detail-photo">
            <PhotoGallery photos={target.photos ?? []} variant="tile" />
          </div>

          <div class="detail-info">
            <div class="detail-head">
              <h3 class="detail-name">{target.name}</h3>
              {#if canEdit}
                <div class="detail-actions">
                  <button
                    type="button"
                    class="icon-btn"
                    title="Редактировать карточку позиции"
                    aria-label={`Редактировать позицию: ${target.name}`}
                    onclick={() => openItemPage(target)}
                  >
                    <Icon name="edit" size={16} />
                  </button>
                </div>
              {/if}
            </div>

            {#if target.warehouse_name || (groupsEnabled && target.group_title)}
              <div class="meta">
                {#if warehousesEnabled && target.warehouse_name}<span class="wh">{target.warehouse_name}</span>{/if}
                {#if groupsEnabled && target.group_title}<span class="group">{target.group_title}</span>{/if}
              </div>
            {/if}

            {#if target.description !== ''}
              <p class="detail-desc">{target.description}</p>
            {/if}

            <div class="detail-fields">
              <div class="field-row">
                <span class="field-label">Остаток на складе</span>
                <span class="field-value quantity" class:on-order={target.quantity < 0}>
                  {stockQuantityText(target.quantity, target.unit)}
                </span>
              </div>

              {#if pricesEnabled}
                <div class="field-row">
                  <span class="field-label">Цена</span>
                  <span class="field-value price" class:missing={target.price === null}>
                    {formatPrice(target.price)}
                  </span>
                </div>
              {/if}
            </div>

            {#if canCreate}
              <div class="detail-cart">
                {#if detailCartQty > 0}
                  <span class="stat-label">В корзине</span>
                  <span class="add">
                    <button
                      type="button"
                      class="step"
                      aria-label={`Уменьшить: ${target.name}`}
                      onclick={() => cart.decreaseAt(target.warehouse_id, target.name)}
                    >
                      −
                    </button>
                    <span class="qty" aria-label={`В корзине: ${target.name}`}>
                      {detailCartQty.toLocaleString('ru-RU', { maximumFractionDigits: 3 })}
                    </span>
                    <button
                      type="button"
                      class="step"
                      aria-label={`Увеличить: ${target.name}`}
                      onclick={() => cart.increaseAt(target.warehouse_id, target.name)}
                    >
                      +
                    </button>
                  </span>
                {:else}
                  <Button onclick={() => addToCart(target)}>Добавить в корзину</Button>
                {/if}
              </div>
            {/if}
          </div>
        </div>
      {/if}
    </Modal>

    <Modal open={helpOpen} title="Как пользоваться" onclose={() => (helpOpen = false)} wide>
      <div class="help">
        <p class="help-intro">
          Эта страница показывает, сколько товаров есть на складах. Ниже — что делает каждая
          кнопка и как всё устроено. Всё просто, разберётся даже школьник.
        </p>

        <div class="help-block">
          <h3>Кнопки сверху</h3>
          <ul>
            <li>
              <b>Значок склада</b> — выбрать склады. Отметь галочками один или сразу несколько:
              тогда товары покажутся вместе, а у каждой строки будет написан склад. Если не
              выбрать ни одного — список будет пустым.
            </li>
            <li>
              <b>Значок сортировки</b> (стрелочки ↑↓) — расставить товары по порядку. Можно
              задать несколько условий: например, «сначала по складу, потом по цене». Стрелка
              вверх — от меньшего к большему, вниз — наоборот.
            </li>
            {#if groupsEnabled}
              <li>
                <b>Значок групп</b> (слои) — оставить только нужные группы товаров. Галочками
                отметь группы; «Без группы» — товары без группы. По умолчанию отмечены все.
              </li>
            {/if}
            <li>
              <b>Значок отображения</b> (квадратики) — показать товары списком или плитками, а
              также включить или скрыть фото. На телефоне плитки идут в 2 столбика, на компьютере —
              в 3. Выбор запоминается.
            </li>
            {#if canEdit}
              <li>
                <b>Карандаш</b> — в карточке позиции открывает страницу редактирования: там можно
                изменить параметры и управлять фото (до 10 штук, jpg/png/webp, до 5 МБ).
              </li>
            {/if}
            <li>
              <b>Значок экспорта</b> (стрелка вниз) — скачать список товаров файлом: Excel,
              CSV, TXT или PDF. Можно отправить файл на почту, если почта настроена.
            </li>
            {#if canImport}
              <li><b>История импорта</b> — посмотреть, когда и что загружали на склад.</li>
            {/if}
          </ul>
        </div>

        <div class="help-block">
          <h3>Поиск</h3>
          <p>
            Поле слева — это поиск. Напиши часть названия (например, <b>iPhone</b>) — список сам
            покажет подходящие товары, нажимать ничего не надо. Крестик в поле очищает поиск.
            Если щёлкнуть по пустому полю, покажутся прошлые запросы.
          </p>
        </div>

        <div class="help-block">
          <h3>Сколько показывать</h3>
          <p>
            Внизу списка есть «Показывать»: 10, 20, 50, 100 или «Все». Так ты решаешь, сколько
            строк видно за раз. Твой выбор запоминается.
          </p>
        </div>

        {#if canCreate}
          <div class="help-block">
            <h3>Как заказать товар (сделать заявку)</h3>
            <ol>
              <li>Найди нужный товар через поиск.</li>
              <li>Нажми в строке товара <b>«Добавить в корзину»</b> — товар попадёт в заявку.</li>
              <li>Нужно больше — нажми <b>«+»</b>; <b>«−»</b> — уменьшить количество.</li>
              <li>
                Когда всё выбрано, открой <b>корзину</b> (значок корзины вверху, там видно число
                товаров) и отправь заявку.
              </li>
            </ol>
            <p class="help-note">
              Чтобы убрать товар из заявки: нажми «−», пока количество не станет 0, либо удали
              его на странице корзины.
            </p>
          </div>
        {/if}

        <div class="help-block">
          <h3>Что означают отметки</h3>
          <ul>
            <li><b>«нет в наличии»</b> — на складе сейчас 0 штук.</li>
            <li><b>«поз заказ»</b> — товар можно заказать даже в минус (если так разрешено).</li>
            {#if pricesEnabled}
              <li><b>Цена</b> — сколько товар стоит для тебя.</li>
            {/if}
            {#if groupsEnabled}
              <li><b>Группа</b> — к какой группе относится товар.</li>
            {/if}
          </ul>
        </div>

        {#if canEdit}
          <div class="help-block">
            <h3>Для администратора</h3>
            <ul>
              <li><b>«+ Позиция»</b> — добавить новый товар на склад.</li>
              <li><b>«Переименовать склад»</b> — поменять название склада.</li>
              <li><b>Карандаш</b> в карточке позиции — открыть редактирование позиции.</li>
            </ul>
          </div>
        {/if}

        {#if canImport}
          <div class="help-block">
            <h3>Для менеджера</h3>
            <ul>
              <li><b>«Импорт остатков из 1С»</b> — загрузить свежие остатки из файла.</li>
            </ul>
          </div>
        {/if}
      </div>
    </Modal>

    {#if loadingLevels}
      <div class="center"><Spinner size={26} /></div>
    {:else if selectedIds.length === 0}
      <div class="empty">Нет выбранных складов — нет позиций</div>
    {:else if levels.length === 0}
      <div class="empty">Ничего не найдено</div>
    {:else}
      <div class="levels-wrap">
      {#if viewMode === 'list'}
        <div class="levels-head" style={listVars}>
          <button type="button" class="levels-head-cell col-name" onclick={() => headerSort('name')}>
            <span class="levels-head-label">Название</span>
            {#if sortIndex('name') > 0}
              <span class="sort-badge">
                <Icon name={sortTerm('name') === 'desc' ? 'arrow-down' : 'arrow-up'} size={12} />
                <span class="sort-num">{sortIndex('name')}</span>
              </span>
            {/if}
          </button>
          <button type="button" class="levels-head-cell col-stock" onclick={() => headerSort('qty')}>
            <span class="levels-head-label">Остаток на складе</span>
            {#if sortIndex('qty') > 0}
              <span class="sort-badge">
                <Icon name={sortTerm('qty') === 'desc' ? 'arrow-down' : 'arrow-up'} size={12} />
                <span class="sort-num">{sortIndex('qty')}</span>
              </span>
            {/if}
          </button>
          {#if pricesEnabled}
            <button type="button" class="levels-head-cell col-price" onclick={() => headerSort('price')}>
              <span class="levels-head-label">Цена</span>
              {#if sortIndex('price') > 0}
                <span class="sort-badge">
                  <Icon name={sortTerm('price') === 'desc' ? 'arrow-down' : 'arrow-up'} size={12} />
                  <span class="sort-num">{sortIndex('price')}</span>
                </span>
              {/if}
            </button>
          {/if}
          {#if canCreate}<span class="levels-head-cell head-cart col-cart">Корзина</span>{/if}
        </div>
      {/if}
      <div class="levels" class:tiles={viewMode === 'tiles'} style={listVars}>
        {#each levels as level (level.id)}
          {@const cartQty = inCart(level)}
          <!-- svelte-ignore a11y_click_events_have_key_events -->
          <!-- svelte-ignore a11y_no_static_element_interactions -->
          <div
            class="level"
            class:cart-over={cartQty > 0 && cartQty > level.quantity}
            class:inactive={level.active === false}
            onclick={() => openDetail(level)}
          >
            {#if showPhotos}
              <!-- svelte-ignore a11y_click_events_have_key_events -->
              <!-- svelte-ignore a11y_no_static_element_interactions -->
              <div class="cell col-photo" onclick={(event) => event.stopPropagation()}>
                <PhotoGallery
                  photos={level.photos ?? []}
                  variant={viewMode === 'tiles' ? 'tile' : 'list'}
                  flush={viewMode === 'tiles'}
                  onopen={() => openDetail(level)}
                />
              </div>
            {/if}
            <div class="name-col col-name">
              <button
                type="button"
                class="name"
                class:has-desc={level.description !== ''}
                title="Подробнее о позиции"
                onclick={(event) => {
                  event.stopPropagation();
                  openDetail(level);
                }}
              >
                {level.name}
              </button>

              {#if level.warehouse_name || (groupsEnabled && level.group_title) || level.active === false}
                <div class="meta">
                  {#if warehousesEnabled && level.warehouse_name}<span class="wh">{level.warehouse_name}</span>{/if}
                  {#if groupsEnabled && level.group_title}<span class="group">{level.group_title}</span>{/if}
                  {#if level.active === false}<span class="inactive-tag">деактивирована</span>{/if}
                </div>
              {/if}
            </div>
            <div class="level-bottom">
              <div class="stat col-stock">
                <span class="stat-label">Остаток на складе</span>
                <span class="quantity" class:on-order={level.quantity < 0}>
                  {stockQuantityText(level.quantity, level.unit)}
                </span>
                {#if level.quantity === 0}
                  <span class="zero-mark">нет в наличии</span>
                {/if}
              </div>

              {#if pricesEnabled}
                <div class="stat col-price">
                  <span class="stat-label">Цена</span>
                  <span
                    class="price"
                    class:missing={level.price === null}
                    title={level.price === null ? 'Цена не задана' : 'Цена'}
                  >
                    {formatPrice(level.price)}
                  </span>
                </div>
              {/if}

              {#if canCreate && level.active !== false}
                <!-- svelte-ignore a11y_click_events_have_key_events -->
                <!-- svelte-ignore a11y_no_static_element_interactions -->
                <div class="stat cart-stat col-cart" onclick={(event) => event.stopPropagation()}>
                  <span class="stat-label">В корзине</span>
                  {#if cartQty > 0}
                    <span class="add">
                      <button
                        type="button"
                        class="step"
                        aria-label={`Уменьшить: ${level.name}`}
                        onclick={() => cart.decreaseAt(level.warehouse_id, level.name)}
                      >
                        −
                      </button>
                      <span class="qty" aria-label={`В корзине: ${level.name}`}>
                        {cartQty.toLocaleString('ru-RU', { maximumFractionDigits: 3 })}
                      </span>
                      <button
                        type="button"
                        class="step"
                        aria-label={`Увеличить: ${level.name}`}
                        onclick={() => cart.increaseAt(level.warehouse_id, level.name)}
                      >
                        +
                      </button>
                    </span>
                  {:else}
                    <span class="add add-empty">
                      <Button
                        variant="text"
                        size="sm"
                        title="Добавить в корзину"
                        ariaLabel={`Добавить в корзину: ${level.name}`}
                        onclick={() => addToCart(level)}
                      >
                        <Icon name="cart" size={16} /> Добавить
                      </Button>
                    </span>
                  {/if}
                </div>
              {/if}
            </div>
          </div>
        {/each}
      </div>
      </div>

      <div class="pager">
        <label class="per-page">
          <span>Показывать</span>
          <select bind:value={perPage} onchange={changePerPage}>
            <option value={10}>10</option>
            <option value={20}>20</option>
            <option value={50}>50</option>
            <option value={100}>100</option>
            <option value={0}>Все</option>
          </select>
        </label>

        {#if pages > 1}
          <div class="pager-main">
            <span class="pager-total">{rangeFrom}–{rangeTo} из {total}</span>

            <div class="pager-pages" aria-label="Постраничная навигация">
              <button
                type="button"
                class="page-btn"
                disabled={page <= 1}
                aria-label="Предыдущая страница"
                onclick={() => void goToPage(page - 1)}
              >
                <Icon name="arrow-left" size={14} />
              </button>

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

              <button
                type="button"
                class="page-btn"
                disabled={page >= pages}
                aria-label="Следующая страница"
                onclick={() => void goToPage(page + 1)}
              >
                <Icon name="arrow-right" size={14} />
              </button>
            </div>

            <div class="pager-simple" aria-label="Постраничная навигация">
              <button
                type="button"
                class="page-btn"
                disabled={page <= 1}
                aria-label="Предыдущая страница"
                onclick={() => void goToPage(page - 1)}
              >
                <Icon name="arrow-left" size={14} />
              </button>
              <span class="pager-simple-count">{page} / {pages}</span>
              <button
                type="button"
                class="page-btn"
                disabled={page >= pages}
                aria-label="Следующая страница"
                onclick={() => void goToPage(page + 1)}
              >
                <Icon name="arrow-right" size={14} />
              </button>
            </div>

            <form class="pager-jump" onsubmit={submitJump}>
              <span>Перейти</span>
              <input type="number" min="1" max={pages} bind:value={jumpPage} aria-label="Номер страницы" />
            </form>
          </div>
        {/if}
      </div>
    {/if}
  {/if}

  <ExportModal
    open={exportOpen}
    title="Экспорт остатков по выбранным складам"
    email={auth.user?.email ?? ''}
    emailAllowed={appSettings.emailExportEnabled && appSettings.mailConfigured}
    onclose={() => (exportOpen = false)}
    onpick={(format, byEmail) => void doExport(format, byEmail)}
  />

</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
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

  .head-subtitle {
    margin: 0;
    font-size: 14px;
    color: var(--text-description);
  }

  .tabs {
    display: flex;
    gap: var(--space-6);
    margin-bottom: var(--space-4);
    border-bottom: 1px solid var(--border-secondary);
  }

  .tab {
    position: relative;
    padding: 10px 0;
    border: none;
    background: none;
    font: inherit;
    font-size: 14px;
    color: var(--text);
    cursor: pointer;
  }

  .tab:hover {
    color: var(--primary);
  }

  .tab.active {
    color: var(--primary);
  }

  .tab.active::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: -1px;
    height: 2px;
    background: var(--primary);
  }

  .import-hint {
    margin: 0 0 var(--space-4);
    font-size: 13px;
    line-height: 1.5;
    color: var(--text-description);
  }

  .import-field {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    margin-bottom: var(--space-4);
  }

  .import-label {
    font-size: 14px;
    color: var(--text);
  }

  .import-field input[type='file'],
  .import-field input[type='date'],
  .import-field select {
    padding: 4px 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    font-size: 14px;
  }

  .count {
    font-size: 12px;
    color: var(--muted);
  }

  .count.found {
    padding: 1px 7px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--primary) 12%, white);
    color: var(--primary);
    font-weight: 600;
  }

  .count.zero {
    opacity: 0.55;
  }

  .picked {
    font-size: 12px;
    font-weight: 600;
    color: var(--primary);
    white-space: nowrap;
  }

  .warehouse-option-name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .personal {
    color: var(--primary);
    font-size: 16px;
    line-height: 1;
  }

  .filters {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-4);
    align-items: center;
    justify-content: space-between;
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
    align-items: center;
    gap: var(--space-2);
  }

  .sort-button {
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

  .toolbar-divider {
    width: 1px;
    height: 24px;
    margin: 0 var(--space-1);
    background: var(--border-secondary);
  }

  .sort-options {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .sort-hint {
    margin: 0 0 var(--space-2);
    font-size: 13px;
    color: var(--text-description);
  }

  .sort-status {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    margin-bottom: var(--space-3);
    padding-bottom: var(--space-3);
    border-bottom: 1px solid var(--border-secondary);
  }

  .sort-status-label {
    font-size: 14px;
    color: var(--text-description);
  }

  .status-opts {
    display: flex;
    gap: var(--space-2);
  }

  .status-opt {
    padding: 0 12px;
    height: 28px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: 14px;
    cursor: pointer;
  }

  .status-opt:hover {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .status-opt.on {
    border-color: var(--primary);
    color: var(--primary);
    font-weight: 500;
  }

  .sort-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .sort-order {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: var(--primary);
    color: #fff;
    font-size: 11px;
    font-weight: 600;
  }

  .sort-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: 6px 8px;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
  }

  .sort-row.active {
    border-color: var(--primary-border);
    background: var(--primary-bg);
  }

  .sort-row-label {
    flex: 1;
    min-width: 0;
    font-size: 14px;
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
    color: var(--text);
    cursor: pointer;
    transition: color 0.15s ease, border-color 0.15s ease, background 0.15s ease;
  }

  .dir-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .dir-btn.on {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--primary-bg);
  }

  .sort-option {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 14px;
    cursor: pointer;
  }

  .sort-option input[type='checkbox'],
  .sort-option input[type='radio'] {
    width: 16px;
    height: 16px;
    margin: 0;
    accent-color: var(--primary);
    cursor: pointer;
  }

  .modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-3);
  }

  .help-intro {
    margin: 0 0 var(--space-4);
    font-size: 14px;
    color: var(--muted);
  }

  .help-block {
    margin-bottom: var(--space-4);
  }

  .help-block:last-child {
    margin-bottom: 0;
  }

  .help-block h3 {
    margin: 0 0 var(--space-2);
    font-size: 15px;
  }

  .help-block p {
    margin: 0;
    font-size: 14px;
  }

  .help ul,
  .help ol {
    margin: 0;
    padding-left: 20px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 14px;
  }

  .help-note {
    margin: var(--space-2) 0 0;
    font-size: 13px;
    color: var(--muted);
  }

  @media (max-width: 720px) {
    .search {
      flex: 1 1 100%;
      max-width: none;
    }
  }

  .levels {
    display: flex;
    flex-direction: column;
    background: var(--surface);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    overflow: hidden;
  }

  .levels-wrap {
    display: flex;
    flex-direction: column;
  }

  .levels-head {
    display: none;
  }

  .cell {
    flex: 0 0 auto;
    min-width: 0;
  }

  .col-photo {
    grid-column: 1;
  }

  .col-name {
    grid-column: 2;
  }

  .col-stock {
    grid-column: 3;
  }

  .col-price {
    grid-column: 4;
  }

  .col-cart {
    grid-column: 5;
  }

  .levels-head-cell {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 4px;
    min-width: 0;
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
  }

  .levels-head-label {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  button.levels-head-cell {
    cursor: pointer;
  }

  button.levels-head-cell:hover {
    color: var(--primary);
  }

  .head-cart {
    justify-content: center;
    cursor: default;
  }

  .sort-badge {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    flex: 0 0 auto;
    color: var(--primary);
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

  @media (min-width: 720px) {
    .levels-head {
      position: sticky;
      top: 0;
      z-index: 2;
      display: grid;
      grid-template-columns:
        var(--photo, 88px) minmax(0, 1fr) minmax(0, 130px) var(--price, 100px)
        var(--cart, 120px);
      align-items: stretch;
      gap: 0;
      padding: 0;
      background: var(--fill-tertiary);
      border: 1px solid var(--border-secondary);
      border-bottom: 1px solid var(--border-secondary);
      border-radius: var(--radius-md) var(--radius-md) 0 0;
      font-size: 14px;
      font-weight: 600;
      color: var(--text);
    }

    .levels-head-cell {
      position: relative;
      padding: 12px var(--space-4);
    }

    .levels-head-cell:not(:last-child)::after {
      content: '';
      position: absolute;
      top: 50%;
      right: 0;
      width: 1px;
      height: 1.6em;
      background: var(--border-secondary);
      transform: translateY(-50%);
    }

    .levels-head .col-stock,
    .levels-head .col-price {
      justify-content: flex-end;
    }

    .levels:not(.tiles) {
      border-top: none;
      border-radius: 0 0 var(--radius-md) var(--radius-md);
    }

    .levels:not(.tiles) .level {
      display: grid;
      grid-template-columns:
        var(--photo, 88px) minmax(0, 1fr) minmax(0, 130px) var(--price, 100px)
        var(--cart, 120px);
      align-items: center;
      gap: 0;
      padding: 0;
    }

    .levels:not(.tiles) .level > .cell,
    .levels:not(.tiles) .level > .name-col,
    .levels:not(.tiles) .level > .level-bottom > .stat {
      padding: 12px var(--space-4);
    }

    .levels:not(.tiles) .level-bottom {
      display: contents;
    }

    .levels:not(.tiles) .stat-label {
      display: none;
    }

    .levels:not(.tiles) .name {
      font-weight: 600;
    }

    .levels:not(.tiles) .col-stock,
    .levels:not(.tiles) .col-price {
      align-items: flex-end;
      text-align: right;
    }

    .levels:not(.tiles) .cart-stat {
      align-items: center;
    }

    .levels:not(.tiles) .cart-stat .add-empty {
      justify-content: center;
    }
  }

  .level {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: 12px var(--space-4);
    border-bottom: 1px solid var(--border-secondary);
    font-size: 14px;
    cursor: pointer;
    transition: background 0.12s ease;
  }

  .level:hover {
    background: var(--fill-tertiary);
  }

  .level:last-child {
    border-bottom: none;
  }

  .level-bottom {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 6px var(--space-3);
    min-width: 0;
  }

  .cart-stat {
    cursor: default;
  }

  .stat {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .stat-label {
    font-size: 12px;
    line-height: 1.2;
    color: var(--muted);
    white-space: nowrap;
  }

  .detail {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: var(--space-4);
    align-items: start;
  }

  .detail-photo {
    min-width: 0;
  }

  .detail-info {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    min-width: 0;
  }

  .detail-name {
    margin: 0;
    font-size: 16px;
    overflow-wrap: anywhere;
  }

  .detail-desc {
    margin: 0;
    color: var(--muted);
    font-size: 14px;
    line-height: 1.5;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }

  .detail-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-3);
  }

  .detail-actions {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex: 0 0 auto;
  }

  .detail-fields {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding: var(--space-3);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    background: var(--surface);
  }

  .field-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-3);
  }

  .field-label {
    font-size: 14px;
    color: var(--text-description);
  }

  .field-value {
    font-weight: 500;
    text-align: right;
  }

  .field-value.on-order {
    color: var(--warning-text);
  }

  .field-value.missing {
    color: var(--muted);
    font-weight: 400;
  }

  .detail-cart {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-2);
  }

  @media (max-width: 560px) {
    .detail {
      grid-template-columns: minmax(0, 1fr);
    }
  }

  .levels.tiles {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-4);
    padding: 0;
    background: transparent;
    border: none;
    border-radius: 0;
    overflow: visible;
  }

  @media (min-width: 640px) {
    .levels.tiles {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }
  }

  @media (min-width: 1024px) {
    .levels.tiles {
      grid-template-columns: repeat(4, minmax(0, 1fr));
    }
  }

  @media (max-width: 719.98px) {
    .levels:not(.tiles) .level {
      flex-wrap: wrap;
      row-gap: var(--space-2);
    }

    .levels:not(.tiles) .name-col {
      flex: 1 1 60%;
    }

    .levels:not(.tiles) .level-bottom {
      flex: 1 1 100%;
      justify-content: flex-start;
    }
  }

  .levels.tiles .level {
    flex-direction: column;
    align-items: stretch;
    justify-content: flex-start;
    gap: 0;
    padding: 0;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    background: var(--surface);
    overflow: hidden;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
  }

  .levels.tiles .level:hover {
    border-color: var(--border-secondary);
    box-shadow: var(--shadow-md);
  }

  .levels.tiles .level:last-child {
    border-bottom: 1px solid var(--border-secondary);
  }

  .levels.tiles .cell.col-photo {
    width: 100%;
  }

  .levels.tiles .name-col {
    gap: var(--space-2);
    padding: var(--space-3) var(--space-3) 0;
  }

  .levels.tiles .name {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    font-weight: 600;
    line-height: 1.3;
  }

  .levels.tiles .meta {
    flex-wrap: nowrap;
    gap: var(--space-1);
    font-size: 12px;
  }

  .levels.tiles .meta .wh,
  .levels.tiles .meta .group {
    max-width: 100%;
    padding: 0 7px;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: var(--fill-tertiary);
    color: var(--text-description);
    line-height: 20px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .levels.tiles .stat-label {
    display: none;
  }

  .levels.tiles .level-bottom {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px var(--space-3);
    margin-top: auto;
    padding: var(--space-3);
  }

  .levels.tiles .col-price {
    order: 1;
  }

  .levels.tiles .col-stock {
    order: 2;
    color: var(--text-description);
  }

  .levels.tiles .col-price .price {
    font-size: 16px;
    font-weight: 600;
  }

  .levels.tiles .col-stock .quantity {
    font-size: 13px;
    font-weight: 400;
  }

  .levels.tiles .cart-stat {
    order: 3;
    flex: 1 0 100%;
    min-width: 0;
    margin: 0 calc(-1 * var(--space-3)) calc(-1 * var(--space-3));
    padding: var(--space-2) var(--space-3);
    border-top: 1px solid var(--border-secondary);
    background: var(--surface);
    justify-content: center;
  }

  .name-col {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
    min-width: 0;
  }

  .meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2);
  }

  .level.inactive {
    background: var(--danger-bg);
  }

  .inactive-tag {
    padding: 0 7px;
    border: 1px solid var(--danger);
    border-radius: 4px;
    background: var(--surface);
    font-size: 12px;
    line-height: 18px;
    color: var(--danger);
  }

  .name {
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
    overflow-wrap: anywhere;
  }

  .level:hover .name {
    color: var(--primary);
  }

  .level .quantity {
    font-weight: 500;
    white-space: nowrap;
  }

  .level .quantity.on-order {
    color: var(--warning-text);
    font-weight: 600;
  }

  .level .group {
    color: var(--muted);
    font-size: 12px;
  }

  .level .wh {
    color: var(--muted);
    font-size: 12px;
  }

  .level .price {
    font-weight: 600;
    white-space: nowrap;
  }

  .level .price.missing {
    color: var(--muted);
    font-weight: 400;
  }

  .zero-mark {
    font-size: 12px;
    color: var(--muted);
    white-space: nowrap;
  }

  .icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--muted);
    cursor: pointer;
    flex: 0 0 auto;
  }

  .icon-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .view-columns {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-4);
  }

  .view-column {
    min-width: 0;
  }

  .view-column + .view-column {
    padding-left: var(--space-4);
    border-left: 1px solid var(--border-secondary);
  }

  .levels .level.cart-over .qty {
    color: var(--danger);
  }

  .add {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    flex: 0 0 auto;
  }

  .cart-stat {
    min-width: 0;
  }

  .cart-stat .add {
    width: 100%;
    justify-content: center;
  }

  .cart-stat .add-empty {
    justify-content: center;
  }

  .qty {
    min-width: 26px;
    text-align: center;
    font-weight: 500;
    white-space: nowrap;
  }

  .step {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: none;
    background: none;
    color: var(--text);
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
  }

  .step:hover {
    color: var(--primary);
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

  .history {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .job {
    padding: var(--space-3);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
  }

  .job .row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .file {
    font-weight: 500;
  }

  .status {
    font-size: 12px;
    color: var(--success-text);
  }

  .status.failed {
    color: var(--error-text);
  }

  .job-meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    font-size: 12px;
    color: var(--muted);
    margin-top: 2px;
  }

  .warn {
    color: var(--warning-text);
  }

  .errors {
    margin-top: 4px;
    font-size: 12px;
    color: var(--error-text);
    white-space: pre-wrap;
  }

  .alert,
  .notice {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    color: var(--text);
    font-size: 14px;
  }

  .alert {
    border-color: var(--error-border);
    background: var(--danger-bg);
  }

  .notice {
    border-color: var(--success-border);
    background: var(--success-bg);
  }

  .alert-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
    color: var(--danger);
  }

  .notice-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
    color: var(--success);
  }

  .empty {
    padding: var(--space-6);
    text-align: center;
    color: var(--text-description);
    background: var(--surface);
    border: 1px dashed var(--border-secondary);
    border-radius: var(--radius-md);
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }
</style>
