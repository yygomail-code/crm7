<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    createStockItem,
    deleteItemPhoto,
    emailLevels,
    exportLevels,
    importHistory,
    importLevels,
    invalidateItemPhoto,
    listLevels,
    listWarehouses,
    loadItemPhotoUrl,
    renameWarehouse,
    searchCounts,
    updateStockItem,
    uploadItemPhoto,
    type StockFilters
  } from '../lib/api/stocks';
  import type {
    StockImportJob,
    StockLevel,
    StockPhoto,
    StockUpdate,
    StockWarehouse,
    PriceType,
    ItemGroup
  } from '../lib/api/types';
  import { listPriceTypes } from '../lib/api/prices';
  import { listItemGroups } from '../lib/api/groups';
  import Button from '../lib/components/ui/Button.svelte';
  import ExportModal from '../lib/components/ui/ExportModal.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Input from '../lib/components/ui/Input.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import PhotoGallery from '../lib/components/stocks/PhotoGallery.svelte';
  import { cart } from '../lib/stores/cart.svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { addHistory } from '../lib/search-history';
  import { loadFilters, saveFilters } from '../lib/filters';
  import { formatDate, formatDateTime, formatPrice, stockQuantityText } from '../lib/format';

  let warehouses = $state<StockWarehouse[]>([]);
  let levels = $state<StockLevel[]>([]);
  let jobs = $state<StockImportJob[]>([]);
  let updates = $state<StockUpdate[]>([]);
  let detailOpen = $state(false);
  let detailTarget = $state<StockLevel | null>(null);
  let selectedIds = $state<number[]>([]);
  let query = $state('');
  let searchInput = $state('');
  let page = $state(1);
  let total = $state(0);
  let loading = $state(true);
  let loadingLevels = $state(false);
  let error = $state('');
  let message = $state('');
  let canImport = $state(false);
  let canEdit = $state(false);
  let busy = $state(false);
  let file = $state<File | null>(null);
  let actualDate = $state(new Date().toISOString().slice(0, 10));
  let showHistory = $state(false);
  let importOpen = $state(false);
  let exportOpen = $state(false);
  let counts = $state<Record<string, number>>({});

  let itemOpen = $state(false);
  let itemBusy = $state(false);
  let itemError = $state('');
  let itemTarget = $state<StockLevel | null>(null);
  let itemForm = $state({ name: '', unit: '', quantity: '0', description: '', group_id: 0 });
  let itemPrices = $state<Record<string, string>>({});
  let priceTypes = $state<PriceType[]>([]);
  let groups = $state<ItemGroup[]>([]);

  let renameOpen = $state(false);
  let renameBusy = $state(false);
  let renameError = $state('');
  let renameName = $state('');

  const stockFilterDefaults = {
    sort: 'name_asc',
    group_ids: null as number[] | null,
    group_no_group: true,
    per_page: 20,
    view: 'list',
    photos: true
  };
  const sortFields = [
    { field: 'name', label: 'Наименование' },
    { field: 'qty', label: 'Остаток на складе' },
    { field: 'warehouse', label: 'Склад' },
    { field: 'price', label: 'Цена' }
  ];
  const initialStockFilters = loadFilters('stocks', stockFilterDefaults);
  const groupsPreselected =
    Array.isArray(initialStockFilters.group_ids) && initialStockFilters.group_ids.length > 0;
  let stockSort = $state(initialStockFilters.sort);
  let stockGroups = $state<number[]>(parseGroups(initialStockFilters.group_ids));
  let stockNoGroup = $state<boolean>(initialStockFilters.group_no_group !== false);
  let perPage = $state<number>(typeof initialStockFilters.per_page === 'number' ? initialStockFilters.per_page : 20);
  let viewMode = $state<'list' | 'tiles'>(initialStockFilters.view === 'tiles' ? 'tiles' : 'list');
  let showPhotos = $state<boolean>(initialStockFilters.photos !== false);
  let viewOpen = $state(false);
  let viewDraft = $state<'list' | 'tiles'>('list');
  let photoDraft = $state<boolean>(true);
  let photoOpen = $state(false);
  let photoTarget = $state<StockLevel | null>(null);
  let photoList = $state<StockPhoto[]>([]);
  let photoUrls = $state<Record<number, string | null>>({});
  let photoBusy = $state(false);
  let photoError = $state('');
  let sortOpen = $state(false);
  let sortDraft = $state<string[]>(parseSort(initialStockFilters.sort));
  let groupOpen = $state(false);
  let groupDraft = $state<number[]>([]);
  let groupNoGroupDraft = $state<boolean>(true);
  let warehouseOpen = $state(false);
  let warehouseDraft = $state<number[]>([]);
  let helpOpen = $state(false);
  let itemWarehouseId = $state<number>(0);
  let renameWarehouseId = $state<number>(0);
  let searchTimer: ReturnType<typeof setTimeout> | undefined;

  const canCreate = $derived(auth.can('requests.create') && appSettings.cartEnabled);
  const canManagePhotos = $derived(canEdit || canImport);
  const pages = $derived(perPage <= 0 ? 1 : Math.max(1, Math.ceil(total / perPage)));
  const pricesEnabled = $derived(appSettings.pricesEnabled);
  const groupsEnabled = $derived(appSettings.groupsEnabled);
  const listVars = $derived(
    `--photo: ${showPhotos ? '56px' : '0px'}; --price: ${pricesEnabled ? '100px' : '0px'}; --cart: ${canCreate ? '120px' : '0px'}; --actions: ${canManagePhotos || canEdit ? '72px' : '0px'};`
  );

  $effect(() => {
    if (pricesEnabled && canEdit && priceTypes.length === 0) {
      void loadPriceTypes();
    }
  });

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

  async function loadPriceTypes(): Promise<void> {
    try {
      const data = await listPriceTypes();
      priceTypes = data.items;
    } catch {
      priceTypes = [];
    }
  }

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
      photos: showPhotos
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

  $effect(() => {
    for (const photo of photoList) {
      if (photoUrls[photo.id] !== undefined) {
        continue;
      }

      void loadItemPhotoUrl(photo.id).then((url) => {
        photoUrls[photo.id] = url;
      });
    }
  });

  function openPhotos(level: StockLevel): void {
    photoTarget = level;
    photoList = [...(level.photos ?? [])];
    photoError = '';
    photoOpen = true;
  }

  async function uploadPhotoFile(event: Event): Promise<void> {
    const input = event.currentTarget as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    input.value = '';

    if (file === null || photoTarget === null) {
      return;
    }

    photoBusy = true;
    photoError = '';

    try {
      const result = await uploadItemPhoto(photoTarget.id, file);
      photoList = result.photos;
      photoTarget.photos = result.photos;
    } catch (cause) {
      photoError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить фото';
    } finally {
      photoBusy = false;
    }
  }

  async function removePhoto(id: number): Promise<void> {
    if (photoTarget === null) {
      return;
    }

    photoBusy = true;
    photoError = '';

    try {
      const result = await deleteItemPhoto(id);
      photoList = result.photos;
      photoTarget.photos = result.photos;
      invalidateItemPhoto(id);
    } catch (cause) {
      photoError = cause instanceof ApiError ? cause.message : 'Не удалось удалить фото';
    } finally {
      photoBusy = false;
    }
  }

  function changeSort(): void {
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

  async function loadLevels(): Promise<void> {
    if (selectedIds.length === 0) {
      levels = [];
      total = 0;
      return;
    }

    loadingLevels = true;
    error = '';

    try {
      const data = await listLevels(selectedIds, filters, page, perPage);
      levels = data.items;
      total = data.total;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить остатки';
    } finally {
      loadingLevels = false;
    }
  }

  async function runSearch(record: boolean): Promise<void> {
    query = searchInput.trim();

    if (record && query !== '') {
      addHistory('stocks', query);
    }

    await applyFilters();
  }

  async function search(): Promise<void> {
    clearTimeout(searchTimer);
    await runSearch(true);
  }

  async function changePage(delta: number): Promise<void> {
    if (perPage <= 0) {
      return;
    }

    const next = page + delta;

    if (next < 1 || (next - 1) * perPage >= total) {
      return;
    }

    page = next;
    await loadLevels();
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

  async function doImport(): Promise<void> {
    if (file === null) {
      error = 'Выберите файл (xls, xlsx или csv)';
      return;
    }

    busy = true;
    error = '';
    message = '';

    try {
      const result = await importLevels(file, actualDate);
      message =
        `Импорт: загружено ${result.rows_imported} строк` +
        (result.rows_created > 0 ? `, новых позиций ${result.rows_created}` : '') +
        (result.warehouses_created > 0 ? `, новых складов ${result.warehouses_created}` : '') +
        (result.rows_zeroed > 0 ? `, обнулено ${result.rows_zeroed}` : '') +
        (result.rows_skipped > 0 ? `, пропущено ${result.rows_skipped}` : '');

      if (result.errors.length > 0) {
        message += `. ${result.errors.slice(0, 3).join('; ')}`;
      }

      file = null;
      await init();
      await loadHistory();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось импортировать файл';
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

  async function toggleHistory(): Promise<void> {
    showHistory = !showHistory;

    if (showHistory) {
      await loadHistory();
    }
  }

  function onFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    file = input.files?.[0] ?? null;
  }

  function openItemCreate(): void {
    itemTarget = null;
    itemWarehouseId = selectedIds[0] ?? 0;
    itemForm = { name: '', unit: '', quantity: '0', description: '', group_id: 0 };
    itemPrices = {};
    itemError = '';
    itemOpen = true;
  }

  function openRename(): void {
    if (selectedIds.length === 0) {
      return;
    }

    renameWarehouseId = selectedIds[0];
    renameName = warehouses.find((item) => item.id === renameWarehouseId)?.name ?? '';
    renameError = '';
    renameOpen = true;
  }

  function changeRenameWarehouse(): void {
    renameName = warehouses.find((item) => item.id === renameWarehouseId)?.name ?? '';
  }

  async function saveRename(): Promise<void> {
    if (renameWarehouseId <= 0) {
      return;
    }

    const name = renameName.trim();

    if (name === '') {
      renameError = 'Укажите название склада';
      return;
    }

    renameBusy = true;
    renameError = '';

    try {
      const data = await renameWarehouse(renameWarehouseId, name);
      warehouses = warehouses.map((item) =>
        item.id === data.warehouse.id ? { ...item, name: data.warehouse.name } : item
      );
      renameOpen = false;
      message = `Склад переименован: ${data.warehouse.name}`;
    } catch (cause) {
      renameError = cause instanceof ApiError ? cause.message : 'Не удалось переименовать склад';
    } finally {
      renameBusy = false;
    }
  }

  function openItemEdit(level: StockLevel): void {
    itemTarget = level;
    itemWarehouseId = level.warehouse_id;
    itemForm = {
      name: level.name,
      unit: level.unit,
      quantity: String(level.quantity),
      description: level.description ?? '',
      group_id: level.group_id ?? 0
    };
    itemPrices = Object.fromEntries(
      Object.entries(level.prices ?? {}).map(([typeId, value]) => [typeId, String(value)])
    );
    itemError = '';
    itemOpen = true;
  }

  async function saveItem(): Promise<void> {
    const name = itemForm.name.trim();

    if (name === '') {
      itemError = 'Укажите название позиции';
      return;
    }

    if (itemTarget === null && itemWarehouseId <= 0) {
      itemError = 'Выберите склад';
      return;
    }

    const quantity = Number(itemForm.quantity);

    if (!Number.isFinite(quantity) || quantity < 0) {
      itemError = 'Укажите корректный остаток (не меньше нуля)';
      return;
    }

    const prices: Record<string, number | null> = {};

    if (pricesEnabled && canEdit && priceTypes.length > 0) {
      for (const type of priceTypes) {
        const raw = (itemPrices[String(type.id)] ?? '').trim();

        if (raw === '') {
          prices[String(type.id)] = null;
          continue;
        }

        const value = Number(raw.replace(',', '.'));

        if (!Number.isFinite(value) || value < 0) {
          itemError = `Некорректная цена «${type.title}»`;
          return;
        }

        prices[String(type.id)] = value;
      }
    }

    itemBusy = true;
    itemError = '';

    try {
      const payload = {
        name,
        unit: itemForm.unit.trim(),
        quantity,
        description: itemForm.description.trim(),
        ...(Object.keys(prices).length > 0 ? { prices } : {}),
        ...(groupsEnabled ? { group_id: itemForm.group_id > 0 ? itemForm.group_id : null } : {})
      };

      if (itemTarget === null) {
        await createStockItem(itemWarehouseId, payload);
        message = `Позиция добавлена: ${name}`;
      } else {
        await updateStockItem(itemTarget.id, payload);
        message = `Позиция сохранена: ${name}`;
      }

      itemOpen = false;
      await loadLevels();
      void refreshCounts();
    } catch (cause) {
      itemError = cause instanceof ApiError ? cause.message : 'Не удалось сохранить позицию';
    } finally {
      itemBusy = false;
    }
  }
</script>

<section class="page page-wide">
  <div class="head">
    <h1>
      Складские остатки
      {#if headDate}
        <span class="head-date">(на {formatDate(headDate)})</span>
      {/if}
    </h1>
    <div class="actions">
      {#if canEdit}
        <Button variant="ghost" disabled={selectedIds.length === 0} onclick={openItemCreate}>
          + Позиция
        </Button>
        <Button variant="ghost" disabled={selectedIds.length === 0} onclick={openRename}>
          Переименовать склад
        </Button>
      {/if}
      <button
        type="button"
        class="icon-button"
        title="Как пользоваться"
        aria-label="Как пользоваться страницей"
        onclick={() => (helpOpen = true)}
      >
        <Icon name="help" size={18} />
      </button>
      {#if canImport}
        <Button variant="ghost" onclick={() => void toggleHistory()}>
          {showHistory ? 'Скрыть историю' : 'История импорта'}
        </Button>
      {/if}
    </div>
  </div>

  {#if error}<div class="alert">{error}</div>{/if}
  {#if message}<div class="notice">{message}</div>{/if}

  {#if canImport}
    <details class="card import" open={importOpen}>
      <summary>
        <h2>Импорт остатков из 1С</h2>
        <span class="chev" aria-hidden="true"></span>
      </summary>
      <p class="hint">
        Файл Excel (xls/xlsx) в формате 1С: строки «Склад …» и далее «товар; ед.; остаток на складе».
        CSV: те же три колонки либо «склад; товар; ед.; остаток на складе». Импорт заменяет остатки целиком:
        позиции, которых нет в файле, обнуляются и скрываются, пока в настройках системы не разрешены
        нулевые остатки. Новые склады из файла добавляются автоматически.
      </p>
      <div class="import-row">
        <input type="file" accept=".xls,.xlsx,.csv,.txt" onchange={onFileChange} />
        <label class="date">
          <span>Дата остатков</span>
          <input type="date" bind:value={actualDate} />
        </label>
        <Button loading={busy} disabled={file === null} onclick={() => void doImport()}>Загрузить</Button>
      </div>
    </details>
  {/if}

  {#if showHistory}
    <div class="card">
      <h2>История импорта</h2>
      {#if jobs.length === 0}
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
              <div class="meta">
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
          placeholder="Поиск товара"
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
          <Icon name="sort" size={18} />
        </button>

        <button
          type="button"
          class="sort-button"
          title="Склад"
          aria-label="Выбрать склад"
          onclick={openWarehouse}
        >
          <Icon name="stocks" size={18} />
        </button>

        {#if groupsEnabled}
          <button
            type="button"
            class="sort-button"
            class:active={groupFilterActive}
            title="Группа"
            aria-label="Группа"
            onclick={openGroup}
          >
            <Icon name="group" size={18} />
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
          <Icon name="view" size={18} />
        </button>

        <button
          type="button"
          class="sort-button"
          title="Экспорт"
          aria-label="Экспорт остатков"
          disabled={busy || selectedIds.length === 0}
          onclick={() => (exportOpen = true)}
        >
          <Icon name="export" size={18} />
        </button>
      </div>
    </div>

    <Modal open={sortOpen} title="Сортировка" onclose={() => (sortOpen = false)}>
      <p class="sort-hint">
        Можно задать несколько условий — они применяются сверху вниз. Нажмите стрелку,
        чтобы добавить условие, и повторно — чтобы убрать.
      </p>
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

    <Modal open={photoOpen} title="Фото товара" onclose={() => (photoOpen = false)}>
      {#if photoTarget}
        <p class="sort-hint">{photoTarget.name}</p>
      {/if}

      <div class="photo-grid">
        {#each photoList as photo (photo.id)}
          <div class="photo-item">
            {#if photoUrls[photo.id]}
              <img src={photoUrls[photo.id]} alt="" />
            {/if}
            <button
              type="button"
              class="photo-remove"
              title="Удалить фото"
              aria-label="Удалить фото"
              onclick={() => void removePhoto(photo.id)}
              disabled={photoBusy}
            >
              ×
            </button>
          </div>
        {/each}

        {#if photoList.length < 10}
          <label class="photo-add" class:busy={photoBusy}>
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp"
              onchange={uploadPhotoFile}
              disabled={photoBusy}
            />
            <Icon name="camera" size={20} />
            <span>Добавить</span>
          </label>
        {/if}
      </div>

      <p class="sort-hint">До 10 фото. Форматы: jpg, png, webp. Размер до 5 МБ.</p>

      {#if photoError}<div class="alert">{photoError}</div>{/if}

      <div class="modal-actions">
        <Button onclick={() => (photoOpen = false)}>Готово</Button>
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
            <h3 class="detail-name">{target.name}</h3>

            {#if target.warehouse_name || (groupsEnabled && target.group_title)}
              <div class="meta">
                {#if target.warehouse_name}<span class="wh">{target.warehouse_name}</span>{/if}
                {#if groupsEnabled && target.group_title}<span class="group">{target.group_title}</span>{/if}
              </div>
            {/if}

            {#if target.description !== ''}
              <p class="detail-desc">{target.description}</p>
            {/if}

            <div class="detail-stats">
              <div class="stat">
                <span class="stat-label">Остаток на складе</span>
                <span class="quantity" class:on-order={target.quantity < 0}>
                  {stockQuantityText(target.quantity, target.unit)}
                </span>
              </div>

              {#if pricesEnabled}
                <div class="stat">
                  <span class="stat-label">Цена</span>
                  <span class="price" class:missing={target.price === null}>
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
            {#if canManagePhotos}
              <li>
                <b>Фото товара</b> (фотоаппарат) — добавить до 10 фото к позиции (jpg, png, webp,
                до 5 МБ) или удалить их. Фото видны всем, у кого включено отображение фото.
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
              <li>Нажми в строке товара <b>кнопку с корзинкой</b> — товар попадёт в заявку.</li>
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
              <li><b>Карандаш</b> в строке товара — изменить название, остаток на складе, цену или группу.</li>
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
          <div
            class="level"
            class:in-cart={cartQty > 0}
            class:cart-over={cartQty > 0 && cartQty > level.quantity}
          >
            {#if showPhotos}
              <div class="cell col-photo">
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
                onclick={() => openDetail(level)}
              >
                {level.name}
              </button>

              {#if level.warehouse_name || (groupsEnabled && level.group_title)}
                <button
                  type="button"
                  class="meta meta-btn"
                  title="Подробнее о позиции"
                  onclick={() => openDetail(level)}
                >
                  {#if level.warehouse_name}<span class="wh">{level.warehouse_name}</span>{/if}
                  {#if groupsEnabled && level.group_title}<span class="group">{level.group_title}</span>{/if}
                </button>
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

              {#if canCreate}
                <div class="stat cart-stat col-cart">
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
                      <button
                        type="button"
                        class="cart-btn"
                        title="Добавить в корзину"
                        aria-label={`Добавить в корзину: ${level.name}`}
                        onclick={() => addToCart(level)}
                      >
                        <svg
                          viewBox="0 0 24 24"
                          width="16"
                          height="16"
                          fill="none"
                          stroke="currentColor"
                          stroke-width="2"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          aria-hidden="true"
                        >
                          <circle cx="9" cy="20" r="1.5" />
                          <circle cx="18" cy="20" r="1.5" />
                          <path d="M2.5 3h2.3l2.2 11.8a1.6 1.6 0 0 0 1.6 1.3h8.9a1.6 1.6 0 0 0 1.6-1.3L20.5 7H6" />
                        </svg>
                      </button>
                    </span>
                  {/if}
                </div>
              {/if}

              <div class="cell-actions col-actions">
                {#if canManagePhotos}
                  <button
                    type="button"
                    class="icon-btn"
                    title="Фото товара"
                    aria-label={`Фото: ${level.name}`}
                    onclick={() => openPhotos(level)}
                  >
                    <Icon name="camera" size={16} />
                  </button>
                {/if}
                {#if canEdit}
                  <button
                    type="button"
                    class="icon-btn"
                    title="Изменить позицию"
                    aria-label={`Изменить: ${level.name}`}
                    onclick={() => openItemEdit(level)}
                  >
                    <Icon name="edit" size={16} />
                  </button>
                {/if}
              </div>
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

        <div class="pager-nav">
          <Button variant="ghost" disabled={page <= 1} onclick={() => void changePage(-1)}>Назад</Button>
          <span>Стр. {page} из {pages} · всего {total}</span>
          <Button variant="ghost" disabled={perPage <= 0 || page * perPage >= total} onclick={() => void changePage(1)}>
            Вперёд
          </Button>
        </div>
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

  <Modal
    open={itemOpen}
    title={itemTarget === null ? 'Новая позиция' : 'Позиция'}
    onclose={() => (itemOpen = false)}
  >
    <div class="item-form">
      {#if itemTarget === null && selectedWarehouses.length > 0}
        <label class="field">
          <span class="label">Склад</span>
          <select bind:value={itemWarehouseId}>
            {#each selectedWarehouses as warehouse (warehouse.id)}
              <option value={warehouse.id}>{warehouse.name}</option>
            {/each}
          </select>
        </label>
      {/if}

      <Input label="Название" bind:value={itemForm.name} placeholder="Например: Смартфон Pixel 9" />

      <div class="item-grid">
        <Input label="Единица измерения" bind:value={itemForm.unit} placeholder="шт" />
        <Input label="Остаток на складе" type="number" bind:value={itemForm.quantity} />
      </div>

      {#if groupsEnabled && groups.length > 0}
        <label class="field">
          <span class="label">Группа позиции</span>
          <select bind:value={itemForm.group_id}>
            <option value={0}>Без группы</option>
            {#each groups as group (group.id)}
              <option value={group.id}>{group.title}</option>
            {/each}
          </select>
        </label>
      {/if}

      <label class="field">
        <span class="label">Описание</span>
        <textarea
          bind:value={itemForm.description}
          rows="3"
          placeholder="Характеристики, цвет, память — покажется в подсказке при наведении"
        ></textarea>
      </label>

      {#if pricesEnabled && canEdit && priceTypes.length > 0}
        <div class="field">
          <span class="label">Цены по типам профиля (пусто — цена не задана)</span>
          <div class="item-grid">
            {#each priceTypes as type (type.id)}
              <label class="price-field">
                <span>{type.title}</span>
                <input
                  type="text"
                  inputmode="decimal"
                  placeholder="0,00"
                  bind:value={itemPrices[String(type.id)]}
                />
              </label>
            {/each}
          </div>
        </div>
      {/if}

      {#if itemError}
        <div class="alert">{itemError}</div>
      {/if}

      <div class="modal-actions">
        <Button variant="ghost" onclick={() => (itemOpen = false)}>Отмена</Button>
        <Button loading={itemBusy} onclick={() => void saveItem()}>
          {itemTarget === null ? 'Добавить' : 'Сохранить'}
        </Button>
      </div>
    </div>
  </Modal>

  <Modal open={renameOpen} title="Название склада" onclose={() => (renameOpen = false)}>
    <div class="item-form">
      {#if selectedWarehouses.length > 0}
        <label class="field">
          <span class="label">Склад</span>
          <select bind:value={renameWarehouseId} onchange={changeRenameWarehouse}>
            {#each selectedWarehouses as warehouse (warehouse.id)}
              <option value={warehouse.id}>{warehouse.name}</option>
            {/each}
          </select>
        </label>
      {/if}

      <Input
        label="Название"
        bind:value={renameName}
        placeholder="Например: Склад Тест-Центральный"
      />

      {#if renameError}
        <div class="alert">{renameError}</div>
      {/if}

      <div class="modal-actions">
        <Button variant="ghost" onclick={() => (renameOpen = false)}>Отмена</Button>
        <Button loading={renameBusy} onclick={() => void saveRename()}>Сохранить</Button>
      </div>
    </div>
  </Modal>
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
  }

  h1 {
    margin: 0;
    font-size: 22px;
  }

  h2 {
    margin: 0 0 var(--space-2);
    font-size: 16px;
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .card {
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
  }

  .hint {
    margin: 0 0 var(--space-3);
    font-size: 13px;
    color: var(--muted);
  }

  .import-row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    align-items: flex-end;
  }

  .import summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    cursor: pointer;
    list-style: none;
  }

  .import summary::-webkit-details-marker {
    display: none;
  }

  .import summary h2 {
    margin: 0;
  }

  .import summary .chev {
    width: 8px;
    height: 8px;
    border: solid var(--muted);
    border-width: 0 1.5px 1.5px 0;
    transform: rotate(45deg);
    transition: transform 0.15s ease;
  }

  .import[open] summary .chev {
    transform: rotate(-135deg);
  }

  .import summary:hover .chev {
    border-color: var(--primary);
  }

  .import .hint {
    margin-top: var(--space-2);
  }

  .import-row input[type='file'] {
    flex: 1;
    min-width: 220px;
    font-size: 13px;
  }

  .date {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
    color: var(--muted);
  }

  .date input {
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font: inherit;
  }

  .count {
    font-size: 11px;
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
    font-size: 11px;
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
    gap: var(--space-3);
    align-items: center;
    justify-content: space-between;
  }

  select {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
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
    gap: 6px;
  }

  .icon-button,
  .sort-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--muted);
    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
  }

  .icon-button:hover,
  .sort-button:hover {
    color: var(--text);
    background: rgba(23, 25, 28, 0.04);
  }

  .icon-button:disabled,
  .sort-button:disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }

  .sort-button.active {
    border-color: var(--primary);
    color: var(--primary);
  }

  .sort-options {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .sort-hint {
    margin: 0 0 var(--space-2);
    font-size: 13px;
    color: var(--muted);
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
    border-color: var(--border);
    background: var(--bg);
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
    width: 30px;
    height: 30px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--muted);
    cursor: pointer;
    transition: color 0.15s ease, border-color 0.15s ease, background 0.15s ease;
  }

  .dir-btn:hover {
    color: var(--text);
    background: rgba(23, 25, 28, 0.04);
  }

  .dir-btn.on {
    border-color: var(--primary);
    color: var(--primary);
    background: color-mix(in srgb, var(--primary) 10%, white);
  }

  .sort-option {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 14px;
    cursor: pointer;
  }

  .modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-4);
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

  .head-date {
    font-size: 14px;
    font-weight: 400;
    color: var(--muted);
  }

  .levels {
    display: flex;
    flex-direction: column;
    background: var(--surface);
    border: 1px solid var(--border);
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

  .cell-actions {
    display: flex;
    align-items: center;
    gap: 8px;
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

  .col-actions {
    grid-column: 6;
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
        var(--photo, 56px) minmax(0, 1fr) minmax(0, 130px) var(--price, 100px)
        var(--cart, 120px) var(--actions, 72px);
      align-items: center;
      gap: var(--space-3);
      padding: 10px var(--space-4);
      background: var(--surface);
      border: 1px solid var(--border);
      border-bottom: none;
      border-radius: var(--radius-md) var(--radius-md) 0 0;
      font-size: 12px;
      font-weight: 600;
      color: var(--muted);
    }

    .levels:not(.tiles) {
      border-top: none;
      border-radius: 0 0 var(--radius-md) var(--radius-md);
    }

    .levels:not(.tiles) .level {
      display: grid;
      grid-template-columns:
        var(--photo, 56px) minmax(0, 1fr) minmax(0, 130px) var(--price, 100px)
        var(--cart, 120px) var(--actions, 72px);
      align-items: center;
      gap: var(--space-3);
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
      align-items: flex-start;
    }

    .cell-actions {
      justify-content: flex-end;
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
    padding: 10px var(--space-4);
    border-bottom: 1px solid var(--border);
    font-size: 14px;
    transition: background 0.12s ease;
  }

  .level:hover {
    background: color-mix(in srgb, var(--primary) 6%, white);
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

  .stat {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .stat-label {
    font-size: 11px;
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

  .detail-stats {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3) var(--space-5);
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
    gap: var(--space-5);
    padding: var(--space-4) 0 0;
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
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    overflow: hidden;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }

  .levels.tiles .level:hover {
    border-color: color-mix(in srgb, var(--primary) 30%, var(--border));
    box-shadow: var(--shadow-sm);
  }

  .levels.tiles .level:last-child {
    border-bottom: 1px solid var(--border);
  }

  .levels.tiles .cell.col-photo {
    width: 100%;
  }

  .levels.tiles .name-col {
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
    font-size: 12px;
  }

  .levels.tiles .meta .wh,
  .levels.tiles .meta .group {
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
    gap: var(--space-2) var(--space-3);
    margin-top: auto;
    padding: var(--space-3);
  }

  .levels.tiles .cart-stat {
    min-width: 0;
    margin-left: auto;
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

  .meta-btn {
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
  }

  .meta-btn:hover .wh,
  .meta-btn:hover .group {
    color: var(--primary);
    text-decoration: underline;
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

  .name:hover {
    color: var(--primary);
  }

  .level .quantity {
    font-weight: 500;
    white-space: nowrap;
  }

  .level .quantity.on-order {
    color: #b45309;
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
    border-radius: 50%;
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

  .photo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(72px, 1fr));
    gap: var(--space-2);
    margin-bottom: var(--space-3);
  }

  .photo-item {
    position: relative;
    aspect-ratio: 1;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    overflow: hidden;
    background: var(--bg);
  }

  .photo-item img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .photo-remove {
    position: absolute;
    top: 3px;
    right: 3px;
    width: 20px;
    height: 20px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.55);
    color: #fff;
    font-size: 14px;
    line-height: 1;
    cursor: pointer;
  }

  .photo-remove:disabled {
    opacity: 0.5;
    cursor: default;
  }

  .photo-add {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    aspect-ratio: 1;
    border: 1px dashed var(--border);
    border-radius: var(--radius-sm);
    color: var(--muted);
    font-size: 12px;
    cursor: pointer;
  }

  .photo-add:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .photo-add.busy {
    opacity: 0.6;
    pointer-events: none;
  }

  .photo-add input {
    display: none;
  }

  .item-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .item-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-3);
  }

  .item-form .field {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .item-form .label {
    font-size: 13px;
    color: var(--muted);
  }

  .item-form textarea {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    color: var(--text);
    resize: vertical;
  }

  .item-form select {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    color: var(--text);
    outline: none;
  }

  .item-form select:focus {
    border-color: var(--primary);
  }

  .price-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .price-field span {
    font-size: 12px;
    color: var(--muted);
  }

  .price-field input {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    color: var(--text);
    outline: none;
  }

  .price-field input:focus {
    border-color: var(--primary);
  }

  .item-form .modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
  }

  .levels .level.in-cart {
    background: color-mix(in srgb, var(--success) 12%, white);
  }

  .levels .level.in-cart:hover {
    background: color-mix(in srgb, var(--success) 18%, white);
  }

  .levels .level.in-cart.cart-over {
    background: color-mix(in srgb, var(--danger) 12%, white);
  }

  .levels .level.in-cart.cart-over:hover {
    background: color-mix(in srgb, var(--danger) 18%, white);
  }

  .add {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    flex: 0 0 auto;
  }

  .cart-stat {
    min-width: 120px;
  }

  .cart-stat .add {
    width: 100%;
    justify-content: center;
  }

  .cart-stat .add-empty {
    justify-content: flex-end;
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

  .cart-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: none;
    background: none;
    color: var(--primary);
    cursor: pointer;
  }

  .cart-btn:hover {
    color: var(--primary-hover);
  }

  .pager {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: var(--space-3);
    font-size: 13px;
    color: var(--muted);
  }

  .per-page {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .per-page select {
    padding: 6px 8px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    font-size: 13px;
  }

  .pager-nav {
    display: inline-flex;
    align-items: center;
    gap: var(--space-3);
  }

  .history {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .job {
    padding: var(--space-3);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
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
    color: #1e6b3a;
  }

  .status.failed {
    color: #8c1d18;
  }

  .meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    font-size: 12px;
    color: var(--muted);
    margin-top: 2px;
  }

  .warn {
    color: #b45309;
  }

  .errors {
    margin-top: 4px;
    font-size: 12px;
    color: #8c1d18;
    white-space: pre-wrap;
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
