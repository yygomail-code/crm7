<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    downloadMyReport,
    emailMyReport,
    myReportSummary,
    type MyReportFilters
  } from '../lib/api/my-reports';
  import type { MyReportSummary } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import { addHistory } from '../lib/search-history';
  import { formatDate } from '../lib/format';
  import BarChart from '../lib/components/ui/BarChart.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import ExportModal from '../lib/components/ui/ExportModal.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  function iso(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
  }

  function monthStart(): string {
    const now = new Date();

    return iso(new Date(now.getFullYear(), now.getMonth(), 1));
  }

  let from = $state(monthStart());
  let to = $state(iso(new Date()));
  let status = $state('');
  let priority = $state('');
  let warehouseId = $state('');
  let itemInput = $state('');
  let item = $state('');
  let detail = $state<'requests' | 'items'>('requests');
  let summary = $state<MyReportSummary | null>(null);
  let loading = $state(true);
  let busy = $state(false);
  let error = $state('');
  let notice = $state('');
  let exportOpen = $state(false);

  const filters = $derived<MyReportFilters>({
    from,
    to,
    status,
    priority,
    warehouse_id: warehouseId,
    item,
    detail
  });

  const chartPoints = $derived(
    (summary?.dynamics ?? []).map((point) => ({
      label: point.date,
      value: detail === 'items' ? point.items : point.requests
    }))
  );

  function priorityTitle(value: number): string {
    return summary?.priorities.find((row) => row.value === value)?.title ?? String(value);
  }

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      summary = await myReportSummary(filters);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить отчёт';
    } finally {
      loading = false;
    }
  }

  function applyItem(): void {
    item = itemInput.trim();
    addHistory('my-report-item', item);
    void load();
  }

  function resetFilters(): void {
    status = '';
    priority = '';
    warehouseId = '';
    itemInput = '';
    item = '';
    void load();
  }

  async function pickFormat(format: string, byEmail: boolean): Promise<void> {
    exportOpen = false;
    busy = true;
    error = '';
    notice = '';

    try {
      if (byEmail) {
        const result = await emailMyReport(filters, format);
        notice = `Отчёт отправлен на ${result.email}`;
      } else {
        await downloadMyReport(filters, format);
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сформировать отчёт';
    } finally {
      busy = false;
    }
  }
</script>

<section class="page">
  <div class="head">
    <h1>Мои отчёты</h1>
    <div class="actions">
      <Button variant="ghost" loading={busy} onclick={() => (exportOpen = true)}>
        Скачать или отправить
      </Button>
    </div>
  </div>

  {#if error}<div class="alert">{error}</div>{/if}
  {#if notice}<div class="notice">{notice}</div>{/if}

  <div class="filters">
    <label class="filter">
      <span>с</span>
      <input type="date" bind:value={from} onchange={() => void load()} />
    </label>
    <label class="filter">
      <span>по</span>
      <input type="date" bind:value={to} onchange={() => void load()} />
    </label>

    <label class="filter">
      <span>Статус</span>
      <select bind:value={status} onchange={() => void load()}>
        <option value="">все</option>
        {#each summary?.statuses ?? [] as row (row.code)}
          <option value={row.code}>{row.title}</option>
        {/each}
      </select>
    </label>

    <label class="filter">
      <span>Приоритет</span>
      <select bind:value={priority} onchange={() => void load()}>
        <option value="">любой</option>
        {#each summary?.priorities ?? [] as row (row.value)}
          <option value={String(row.value)}>{row.title}</option>
        {/each}
      </select>
    </label>

    <label class="filter">
      <span>Склад</span>
      <select bind:value={warehouseId} onchange={() => void load()}>
        <option value="">все</option>
        {#each summary?.warehouses ?? [] as row (row.id)}
          <option value={String(row.id)}>{row.name}</option>
        {/each}
      </select>
    </label>

    <form class="search" onsubmit={(event) => { event.preventDefault(); applyItem(); }}>
      <SearchInput
        bind:value={itemInput}
        historyKey="my-report-item"
        placeholder="Позиция (товар)"
        onclear={applyItem}
        onpick={applyItem}
      />
      <Button type="submit" variant="ghost">Найти</Button>
    </form>

    <Button variant="ghost" onclick={resetFilters}>Сбросить</Button>
  </div>

  {#if loading && summary === null}
    <div class="center"><Spinner size={26} /></div>
  {:else if summary}
    <div class="cards">
      <div class="stat">
        <span class="stat-value">{summary.requests.total}</span>
        <span class="stat-label">заявок за период</span>
      </div>
      <div class="stat">
        <span class="stat-value">{summary.items.total}</span>
        <span class="stat-label">позиций в заявках</span>
      </div>
      <div class="stat">
        <span class="stat-value">{summary.requests.closed}</span>
        <span class="stat-label">закрыто</span>
      </div>
      <div class="stat">
        <span class="stat-value">{summary.requests.overdue}</span>
        <span class="stat-label">просрочено</span>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <h2>График: {detail === 'items' ? 'позиции' : 'заявки'}</h2>
        <div class="toggle">
          <button type="button" class:active={detail === 'requests'} onclick={() => (detail = 'requests')}>
            Заявки
          </button>
          <button type="button" class:active={detail === 'items'} onclick={() => (detail = 'items')}>
            Позиции
          </button>
        </div>
      </div>

      <BarChart points={chartPoints} />

      <div class="chart-total">
        Период: {formatDate(summary.period.from)} — {formatDate(summary.period.to)} ·
        {#if detail === 'items'}
          позиций: {summary.items.total} ({summary.items.quantity.toLocaleString('ru-RU', { maximumFractionDigits: 3 })} ед.)
        {:else}
          заявок: {summary.requests.total}
        {/if}
      </div>
    </div>

    <div class="card">
      <h2>По статусам</h2>
      {#if summary.requests.by_status.length === 0}
        <p class="empty">Нет данных</p>
      {:else}
        <div class="rows">
          {#each summary.requests.by_status as row (row.code)}
            <div class="row">
              <span class="row-title">{row.title}</span>
              <span class="row-value">{row.count}</span>
            </div>
          {/each}
        </div>
      {/if}

      <h2>По приоритету</h2>
      {#if summary.requests.by_priority.length === 0}
        <p class="empty">Нет данных</p>
      {:else}
        <div class="rows">
          {#each summary.requests.by_priority as row (row.priority)}
            <div class="row">
              <span class="row-title">{priorityTitle(row.priority)}</span>
              <span class="row-value">{row.count}</span>
            </div>
          {/each}
        </div>
      {/if}
    </div>
  {/if}

  <ExportModal
    open={exportOpen}
    title={detail === 'items' ? 'Экспорт: позиции заявок' : 'Экспорт: заявки'}
    email={auth.user?.email ?? ''}
    onclose={() => (exportOpen = false)}
    onpick={(format, byEmail) => void pickFormat(format, byEmail)}
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
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    flex-wrap: wrap;
  }

  h1 {
    margin: 0;
    font-size: 22px;
  }

  h2 {
    margin: 0 0 var(--space-3);
    font-size: 16px;
  }

  .filters {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-3);
  }

  .filter {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--muted);
  }

  .filter input,
  .filter select {
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
    max-width: 420px;
  }

  .cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: var(--space-3);
  }

  .stat {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
  }

  .stat-value {
    font-size: 24px;
    font-weight: 600;
  }

  .stat-label {
    font-size: 13px;
    color: var(--muted);
  }

  .card {
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
  }

  .card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    flex-wrap: wrap;
  }

  .toggle {
    display: inline-flex;
    padding: 2px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--bg);
  }

  .toggle button {
    padding: 5px 14px;
    border: none;
    border-radius: 999px;
    background: none;
    color: var(--muted);
    font: inherit;
    font-size: 13px;
    cursor: pointer;
  }

  .toggle button.active {
    background: var(--surface);
    color: var(--primary);
    font-weight: 500;
    box-shadow: var(--shadow-sm);
  }

  .chart-total {
    margin-top: var(--space-3);
    font-size: 13px;
    color: var(--muted);
  }

  .rows {
    display: flex;
    flex-direction: column;
    margin-bottom: var(--space-4);
  }

  .row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: 7px 0;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
  }

  .row:last-child {
    border-bottom: none;
  }

  .row-value {
    font-weight: 600;
  }

  .empty {
    margin: 0 0 var(--space-4);
    font-size: 13px;
    color: var(--muted);
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

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }
</style>
