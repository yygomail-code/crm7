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
    type StockFilters
  } from '../lib/api/stocks';
  import type { StockImportJob, StockLevel, StockUpdate, StockWarehouse } from '../lib/api/types';
  import Button from '../lib/components/ui/Button.svelte';
  import ExportModal from '../lib/components/ui/ExportModal.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { cart } from '../lib/stores/cart.svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import { addHistory } from '../lib/search-history';
  import { formatDate, formatDateTime } from '../lib/format';

  let warehouses = $state<StockWarehouse[]>([]);
  let levels = $state<StockLevel[]>([]);
  let jobs = $state<StockImportJob[]>([]);
  let updates = $state<StockUpdate[]>([]);
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
  let busy = $state(false);
  let file = $state<File | null>(null);
  let actualDate = $state(new Date().toISOString().slice(0, 10));
  let showHistory = $state(false);
  let exportOpen = $state(false);
  let counts = $state<Record<string, number>>({});
  let qtyOp = $state('gt');
  let qtyValue = $state('');
  let sortBy = $state('name_asc');

  const canCreate = $derived(auth.can('requests.create'));
  const pages = $derived(Math.max(1, Math.ceil(total / perPage)));

  const filters = $derived<StockFilters>({
    q: query,
    qty_op: qtyOp,
    qty: qtyValue.trim(),
    sort: sortBy
  });

  const hasFilters = $derived(query !== '' || (qtyValue.trim() !== '' && qtyOp !== ''));
  const currentWarehouse = $derived(warehouses.find((item) => item.id === activeId) ?? null);

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
      quantity: 1
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

  function resetFilters(): void {
    qtyOp = 'gt';
    qtyValue = '';
    sortBy = 'name_asc';
    searchInput = '';
    query = '';
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

    if (activeId === null) {
      return;
    }

    const warehouse = warehouses.find((item) => item.id === activeId);
    busy = true;
    error = '';
    message = '';

    try {
      if (byEmail) {
        const result = await emailLevels(activeId, filters, format);
        message = `Остатки отправлены на ${result.email}`;
      } else {
        await exportLevels(activeId, filters, warehouse?.name ?? 'склад', format);
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
</script>

<section class="page page-wide">
  <div class="head">
    <h1>
      Склады
      {#if currentWarehouse?.actual_date}
        <span class="head-date">(остатки на {formatDate(currentWarehouse.actual_date)})</span>
      {/if}
    </h1>
    <div class="actions">
      <Button variant="ghost" loading={busy} disabled={activeId === null} onclick={() => (exportOpen = true)}>
        Скачать или отправить
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
    <div class="card import">
      <h2>Импорт остатков из 1С</h2>
      <p class="hint">
        Файл Excel (xls/xlsx) в формате 1С: строки «Склад …» и далее «товар; ед.; количество».
        CSV: те же три колонки либо «склад; товар; ед.; количество». Импорт заменяет остатки целиком.
      </p>
      <div class="import-row">
        <input type="file" accept=".xls,.xlsx,.csv,.txt" onchange={onFileChange} />
        <label class="date">
          <span>Дата остатков</span>
          <input type="date" bind:value={actualDate} />
        </label>
        <Button loading={busy} disabled={file === null} onclick={() => void doImport()}>Загрузить</Button>
      </div>
    </div>
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
    <div class="tabs">
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
        </button>
      {/each}
    </div>

    <div class="filters">
      <select bind:value={qtyOp} onchange={() => void applyFilters()}>
        <option value="gt">Количество больше</option>
        <option value="lt">Количество меньше</option>
      </select>
      <input
        class="qty-input"
        type="number"
        min="0"
        step="any"
        placeholder="значение"
        bind:value={qtyValue}
        onchange={() => void applyFilters()}
      />

      <select bind:value={sortBy} onchange={() => void applyFilters()}>
        <option value="name_asc">Наименование: А–Я</option>
        <option value="name_desc">Наименование: Я–А</option>
        <option value="qty_desc">Количество: по убыванию</option>
        <option value="qty_asc">Количество: по возрастанию</option>
      </select>

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

      <Button variant="ghost" onclick={resetFilters}>Сбросить</Button>
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
            <span class="name">{level.name}</span>
            <span class="quantity">{level.quantity.toLocaleString('ru-RU', { maximumFractionDigits: 3 })} {level.unit}</span>
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
    title="Экспорт остатков"
    email={auth.user?.email ?? ''}
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

  .tabs {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .tab {
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

  .qty-input {
    width: 130px;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    font-size: 13px;
  }



  .search {
    display: flex;
    gap: var(--space-2);
    flex: 1;
    min-width: 220px;
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
  }

  .level:last-child {
    border-bottom: none;
  }

  .level .name {
    flex: 1;
    min-width: 0;
  }

  .level .quantity {
    font-weight: 500;
    white-space: nowrap;
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
