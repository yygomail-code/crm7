<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    createStockItem,
    emailLevels,
    exportLevels,
    importHistory,
    importLevels,
    listLevels,
    listWarehouses,
    renameWarehouse,
    searchCounts,
    updateStockItem,
    type StockFilters
  } from '../lib/api/stocks';
  import type { StockImportJob, StockLevel, StockUpdate, StockWarehouse, PriceType, ItemGroup } from '../lib/api/types';
  import { listPriceTypes } from '../lib/api/prices';
  import { listItemGroups } from '../lib/api/groups';
  import Button from '../lib/components/ui/Button.svelte';
  import ExportModal from '../lib/components/ui/ExportModal.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Input from '../lib/components/ui/Input.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
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
  let expandedLevels = $state<Set<number>>(new Set());
  let activeId = $state<number | null>(null);
  let query = $state('');
  let searchInput = $state('');
  let page = $state(1);
  let total = $state(0);
  let perPage = $state(50);
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

  let tabsEl = $state<HTMLElement | null>(null);
  let tabsOverflow = $state(false);
  let tabsAtStart = $state(true);
  let tabsAtEnd = $state(false);

  function updateTabsScroll(): void {
    const el = tabsEl;

    if (!el) {
      return;
    }

    tabsOverflow = el.scrollWidth - el.clientWidth > 4;
    tabsAtStart = el.scrollLeft <= 4;
    tabsAtEnd = el.scrollLeft + el.clientWidth >= el.scrollWidth - 4;
  }

  function scrollTabs(direction: number): void {
    const el = tabsEl;

    if (!el) {
      return;
    }

    const step = Math.max(220, Math.round(el.clientWidth * 0.6));
    el.scrollBy({ left: direction * step, behavior: 'smooth' });
  }

  function scrollActiveIntoView(): void {
    const el = tabsEl;
    const active = el?.querySelector<HTMLElement>('.tab.active');

    if (!el || !active) {
      return;
    }

    const left = active.offsetLeft;
    const right = left + active.offsetWidth;

    if (left < el.scrollLeft + 4) {
      el.scrollLeft = Math.max(0, left - 8);
    } else if (right > el.scrollLeft + el.clientWidth - 4) {
      el.scrollLeft = right - el.clientWidth + 8;
    }

    updateTabsScroll();
  }

  $effect(() => {
    void warehouses.length;
    void activeId;

    const el = tabsEl;

    if (!el) {
      return;
    }

    const update = (): void => {
      updateTabsScroll();
      scrollActiveIntoView();
    };
    const observer = new ResizeObserver(update);
    observer.observe(el);
    window.addEventListener('resize', update);
    const frame = requestAnimationFrame(update);
    const timer = setTimeout(update, 80);

    return () => {
      observer.disconnect();
      window.removeEventListener('resize', update);
      cancelAnimationFrame(frame);
      clearTimeout(timer);
    };
  });

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

  const stockFilterDefaults = { sort: 'name_asc', group_id: 0 };
  const initialStockFilters = loadFilters('stocks', stockFilterDefaults);
  let stockSort = $state(initialStockFilters.sort);
  let stockGroup = $state(Number(initialStockFilters.group_id) || 0);

  const canCreate = $derived(
    auth.can('requests.create') && appSettings.salesEnabled && appSettings.cartEnabled && appSettings.requestsEnabled
  );
  const pages = $derived(Math.max(1, Math.ceil(total / perPage)));
  const pricesEnabled = $derived(appSettings.pricesEnabled);
  const groupsEnabled = $derived(appSettings.groupsEnabled);

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
  }

  const filters = $derived<StockFilters>({
    q: query,
    sort: stockSort,
    show_zero: appSettings.allowZeroStock,
    group_id: groupsEnabled && stockGroup > 0 ? stockGroup : undefined
  });

  const hasFilters = $derived(query !== '');
  const currentWarehouse = $derived(warehouses.find((item) => item.id === activeId) ?? null);

  function toggleLevelDescription(levelId: number): void {
    const next = new Set(expandedLevels);

    if (next.has(levelId)) {
      next.delete(levelId);
    } else {
      next.add(levelId);
    }

    expandedLevels = next;
  }

  function addToCart(level: StockLevel): void {
    if (activeId === null) {
      return;
    }

    const warehouse = warehouses.find((item) => item.id === activeId);

    cart.add({
      warehouseId: activeId,
      warehouseName: warehouse?.name ?? '',
      name: level.name,
      unit: level.unit,
      quantity: 1,
      stockLevelId: level.id,
      price: level.price ?? null
    });

    message = `Добавлено в корзину: ${level.name}`;
  }

  function inCart(level: StockLevel): number {
    return activeId === null ? 0 : cart.quantityOf(activeId, level.name);
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
        await select(warehouses[0].id);
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить склады';
    } finally {
      loading = false;
    }
  }

  async function select(id: number): Promise<void> {
    activeId = id;
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

  function changeSort(): void {
    saveFilters('stocks', { sort: stockSort, group_id: stockGroup });
    void applyFilters();
  }

  function changeGroup(): void {
    saveFilters('stocks', { sort: stockSort, group_id: stockGroup });
    void applyFilters();
  }

  async function loadLevels(): Promise<void> {
    if (activeId === null) {
      return;
    }

    loadingLevels = true;
    error = '';

    try {
      const data = await listLevels(activeId, filters, page, perPage);
      levels = data.items;
      total = data.total;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить остатки';
    } finally {
      loadingLevels = false;
    }
  }

  async function search(): Promise<void> {
    query = searchInput.trim();
    addHistory('stocks', query);
    await applyFilters();
  }

  async function changePage(delta: number): Promise<void> {
    const next = page + delta;

    if (next < 1 || (next - 1) * perPage >= total) {
      return;
    }

    page = next;
    await loadLevels();
  }

  function changePerPage(): void {
    page = 1;
    void loadLevels();
  }

  async function doExport(format: string, byEmail: boolean): Promise<void> {
    exportOpen = false;

    if (warehouses.length === 0) {
      return;
    }

    busy = true;
    error = '';
    message = '';

    try {
      if (byEmail) {
        const result = await emailLevels(filters, format);
        message = `Остатки по всем складам отправлены на ${result.email}`;
      } else {
        await exportLevels(filters, format);
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
    itemForm = { name: '', unit: '', quantity: '0', description: '', group_id: 0 };
    itemPrices = {};
    itemError = '';
    itemOpen = true;
  }

  function openRename(): void {
    if (activeId === null) {
      return;
    }

    renameName = currentWarehouse?.name ?? '';
    renameError = '';
    renameOpen = true;
  }

  async function saveRename(): Promise<void> {
    if (activeId === null) {
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
      const data = await renameWarehouse(activeId, name);
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
    if (activeId === null) {
      return;
    }

    const name = itemForm.name.trim();

    if (name === '') {
      itemError = 'Укажите название позиции';
      return;
    }

    const quantity = Number(itemForm.quantity);

    if (!Number.isFinite(quantity) || quantity < 0) {
      itemError = 'Укажите корректное количество (не меньше нуля)';
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
        await createStockItem(activeId, payload);
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
      {#if currentWarehouse?.actual_date}
        <span class="head-date">(на {formatDate(currentWarehouse.actual_date)})</span>
      {/if}
    </h1>
    <div class="actions">
      {#if canEdit}
        <Button variant="ghost" disabled={activeId === null} onclick={openItemCreate}>
          + Позиция
        </Button>
        <Button variant="ghost" disabled={activeId === null} onclick={openRename}>
          Переименовать склад
        </Button>
      {/if}
      <Button variant="ghost" loading={busy} disabled={warehouses.length === 0} onclick={() => (exportOpen = true)}>
        Экспорт
      </Button>
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
        Файл Excel (xls/xlsx) в формате 1С: строки «Склад …» и далее «товар; ед.; количество».
        CSV: те же три колонки либо «склад; товар; ед.; количество». Импорт заменяет остатки целиком:
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
    <select
      class="warehouse-select"
      aria-label="Склад"
      value={activeId ?? ''}
      onchange={(event) => void select(Number((event.currentTarget as HTMLSelectElement).value))}
    >
      {#each warehouses as warehouse (warehouse.id)}
        <option value={warehouse.id}>{warehouse.name} — {warehouse.positions}</option>
      {/each}
    </select>

    <div class="tabs-row">
      {#if tabsOverflow}
        <button
          type="button"
          class="tabs-nav"
          title="Прокрутить влево"
          aria-label="Прокрутить список складов влево"
          disabled={tabsAtStart}
          onclick={() => scrollTabs(-1)}
        >
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M15 6l-6 6 6 6" />
          </svg>
        </button>
      {/if}

      <div class="tabs-wrap" class:overflow={tabsOverflow && !tabsAtEnd}>
        <div class="tabs" bind:this={tabsEl} onscroll={updateTabsScroll}>
          {#each warehouses as warehouse (warehouse.id)}
            <button
              type="button"
              class="tab"
              class:active={warehouse.id === activeId}
              onclick={() => void select(warehouse.id)}
            >
              {warehouse.name}
              {#if warehouse.is_personal}<span class="personal" title="Персональный доступ">•</span>{/if}
              {#if hasFilters}
                <span class="count" class:found={(counts[warehouse.id] ?? 0) > 0} class:zero={(counts[warehouse.id] ?? 0) === 0}>
                  {counts[warehouse.id] ?? 0}
                </span>
              {:else}
                <span class="count">{warehouse.positions}</span>
              {/if}
              {#if cartCount(warehouse.id) > 0}
                <span class="picked" title="Позиций в корзине заявки">в заявке: {cartCount(warehouse.id)}</span>
              {/if}
            </button>
          {/each}
        </div>
      </div>

      {#if tabsOverflow}
        <button
          type="button"
          class="tabs-nav"
          title="Прокрутить вправо"
          aria-label="Прокрутить список складов вправо"
          disabled={tabsAtEnd}
          onclick={() => scrollTabs(1)}
        >
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M9 6l6 6-6 6" />
          </svg>
        </button>
      {/if}
    </div>

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
        <Button type="submit" variant="ghost">Найти</Button>
      </form>

      <label class="sort-field">
        <span>Сортировка</span>
        <select bind:value={stockSort} onchange={changeSort}>
          <option value="name_asc">Наименование: А–Я</option>
          <option value="name_desc">Наименование: Я–А</option>
          <option value="qty_desc">Количество: по убыванию</option>
          <option value="qty_asc">Количество: по возрастанию</option>
        </select>
      </label>

      {#if groupsEnabled}
        <label class="sort-field">
          <span>Группа</span>
          <select bind:value={stockGroup} onchange={changeGroup}>
            <option value={0}>Все группы</option>
            {#each groups as group (group.id)}
              <option value={group.id}>{group.title}</option>
            {/each}
          </select>
        </label>
      {/if}
    </div>

    {#if loadingLevels}
      <div class="center"><Spinner size={26} /></div>
    {:else if levels.length === 0}
      <div class="empty">Ничего не найдено</div>
    {:else}
      <div class="levels">
        {#each levels as level (level.id)}
          {@const cartQty = inCart(level)}
          <div class="level" class:in-cart={cartQty > 0}>
            <div class="name-col">
              <button
                type="button"
                class="name"
                class:has-desc={level.description !== ''}
                title={level.description !== ''
                  ? expandedLevels.has(level.id)
                    ? 'Скрыть описание'
                    : 'Показать описание'
                  : ''}
                onclick={() => toggleLevelDescription(level.id)}
              >
                {level.name}
              </button>

              {#if expandedLevels.has(level.id) && level.description !== ''}
                <div class="desc">{level.description}</div>
              {/if}

              {#if groupsEnabled && level.group_title}
                <span class="group">{level.group_title}</span>
              {/if}
            </div>
            {#if level.quantity === 0}
              <span class="zero-mark">нет в наличии</span>
            {/if}
            <span class="quantity" class:on-order={level.quantity < 0}>
              {stockQuantityText(level.quantity, level.unit)}
            </span>
            {#if pricesEnabled}
              <span class="price" class:missing={level.price === null} title={level.price === null ? 'Цена не задана' : 'Цена'}>
                {formatPrice(level.price)}
              </span>
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
            {#if canCreate}
              <span class="add">
                {#if cartQty > 0}
                  <button
                    type="button"
                    class="step"
                    aria-label={`Уменьшить: ${level.name}`}
                    onclick={() => activeId !== null && cart.decreaseAt(activeId, level.name)}
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
                    onclick={() => activeId !== null && cart.increaseAt(activeId, level.name)}
                  >
                    +
                  </button>
                {:else}
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
                {/if}
              </span>
            {/if}
          </div>
        {/each}
      </div>

      <div class="pager">
        <label class="per-page">
          <span>Показывать</span>
          <select bind:value={perPage} onchange={changePerPage}>
            <option value={20}>20</option>
            <option value={50}>50</option>
            <option value={100}>100</option>
          </select>
        </label>

        <div class="pager-nav">
          <Button variant="ghost" disabled={page <= 1} onclick={() => void changePage(-1)}>Назад</Button>
          <span>Стр. {page} из {pages} · всего {total}</span>
          <Button variant="ghost" disabled={page * perPage >= total} onclick={() => void changePage(1)}>
            Вперёд
          </Button>
        </div>
      </div>
    {/if}
  {/if}

  <ExportModal
    open={exportOpen}
    title="Экспорт остатков по всем складам"
    email={auth.user?.email ?? ''}
    emailAllowed={appSettings.emailExportEnabled}
    onclose={() => (exportOpen = false)}
    onpick={(format, byEmail) => void doExport(format, byEmail)}
  />

  <Modal
    open={itemOpen}
    title={itemTarget === null ? 'Новая позиция' : 'Позиция'}
    onclose={() => (itemOpen = false)}
  >
    <div class="item-form">
      <Input label="Название" bind:value={itemForm.name} placeholder="Например: Смартфон Pixel 9" />

      <div class="item-grid">
        <Input label="Единица измерения" bind:value={itemForm.unit} placeholder="шт" />
        <Input label="Количество" type="number" bind:value={itemForm.quantity} />
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

  .tabs-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .tabs-nav {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 30px;
    height: 30px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
    transition: border-color 0.12s ease, color 0.12s ease;
  }

  .tabs-nav:hover:not(:disabled) {
    border-color: var(--primary);
    color: var(--primary);
  }

  .tabs-nav:disabled {
    opacity: 0.4;
    cursor: default;
  }

  .tabs-wrap {
    position: relative;
    flex: 1 1 auto;
    min-width: 0;
  }

  .tabs-wrap.overflow::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: 40px;
    pointer-events: none;
    background: linear-gradient(to right, transparent, var(--bg) 85%);
  }

  .tabs {
    display: flex;
    flex-wrap: nowrap;
    gap: var(--space-2);
    overflow-x: auto;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 2px;
  }

  .tabs::-webkit-scrollbar {
    display: none;
  }

  .tab {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--muted);
    font: inherit;
    font-size: 13px;
    cursor: pointer;
  }

  .tab.active {
    border-color: var(--primary);
    color: var(--primary);
    font-weight: 500;
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

  .warehouse-select {
    display: none;
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    color: var(--text);
  }

  @media (max-width: 720px) {
    .tabs-row {
      display: none;
    }

    .warehouse-select {
      display: block;
    }
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
    background: var(--bg);
  }

  .level:last-child {
    border-bottom: none;
  }

  .name-col {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
    min-width: 0;
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

  .name.has-desc {
    border-bottom: 1px dashed var(--border);
  }

  .name:hover {
    color: var(--primary);
  }

  .desc {
    padding: 8px 10px;
    border-radius: var(--radius-sm);
    background: var(--bg);
    color: var(--muted);
    font-size: 13px;
    white-space: pre-wrap;
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

  .level.in-cart {
    background: color-mix(in srgb, var(--primary) 7%, white);
  }

  .add {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    flex: 0 0 auto;
  }

  .qty {
    min-width: 44px;
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

  .cart-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: none;
    color: var(--primary);
    cursor: pointer;
  }

  .cart-btn:hover {
    border-color: var(--primary);
    background: color-mix(in srgb, var(--primary) 8%, white);
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
