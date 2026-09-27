<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    myReportSummary,
    type MyReportFilters
  } from '../lib/api/my-reports';
  import type { MyReportSummary } from '../lib/api/types';
  import { clearFilters, countActive, loadFilters, saveFilters } from '../lib/filters';
  import { formatDate } from '../lib/format';
  import { clampPeriod, defaultPeriod, todayIso } from '../lib/period';
  import BarChart from '../lib/components/ui/BarChart.svelte';
  import FiltersModal from '../lib/components/ui/FiltersModal.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  const filterDefaults = {
    from: defaultPeriod().from,
    to: defaultPeriod().to,
    status: '',
    priority: '',
    warehouse: ''
  };

  const initialReportFilters = loadFilters('my-reports', filterDefaults);
  let reportFilters = $state({ ...initialReportFilters });
  let reportDraft = $state({ ...initialReportFilters });
  let detail = $state<'requests' | 'items'>('requests');
  let summary = $state<MyReportSummary | null>(null);
  let loading = $state(true);
  let error = $state('');

  const filters = $derived<MyReportFilters>({
    from: reportFilters.from,
    to: reportFilters.to,
    status: reportFilters.status,
    priority: reportFilters.priority,
    warehouse_id: reportFilters.warehouse,
    item: '',
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

  function applyFilterDraft(): void {
    const period = clampPeriod(reportDraft.from, reportDraft.to);

    reportDraft.from = period.from;
    reportDraft.to = period.to;
    reportFilters = { ...reportDraft };
    saveFilters('my-reports', reportFilters);
    void load();
  }

  function resetFilters(): void {
    const period = defaultPeriod();
    const next = { ...filterDefaults, from: period.from, to: period.to };

    reportDraft = { ...next };
    reportFilters = { ...next };
    clearFilters('my-reports');
    void load();
  }
</script>

<section class="page">
  <div class="head">
    <h1>Мои отчёты</h1>
    <div class="actions">
      <FiltersModal
        count={countActive(reportFilters, filterDefaults)}
        onopen={() => (reportDraft = { ...reportFilters })}
        onapply={applyFilterDraft}
        onreset={resetFilters}
      >
        <label class="filter-field">
          <span>Период с</span>
          <input type="date" bind:value={reportDraft.from} max={reportDraft.to} />
        </label>
        <label class="filter-field">
          <span>Период по</span>
          <input type="date" bind:value={reportDraft.to} min={reportDraft.from} max={todayIso()} />
        </label>

        <label class="filter-field">
          <span>Статус</span>
          <select bind:value={reportDraft.status}>
            <option value="">Все</option>
            {#each summary?.statuses ?? [] as row (row.code)}
              <option value={row.code}>{row.title}</option>
            {/each}
          </select>
        </label>

        <label class="filter-field">
          <span>Приоритет</span>
          <select bind:value={reportDraft.priority}>
            <option value="">Любой</option>
            {#each summary?.priorities ?? [] as row (row.value)}
              <option value={String(row.value)}>{row.title}</option>
            {/each}
          </select>
        </label>

        <label class="filter-field">
          <span>Склад</span>
          <select bind:value={reportDraft.warehouse}>
            <option value="">Все</option>
            {#each summary?.warehouses ?? [] as row (`${row.id}|${row.name}`)}
              <option value={String(row.id)}>{row.name}</option>
            {/each}
          </select>
        </label>
      </FiltersModal>
    </div>
  </div>

  {#if error}<div class="alert">{error}</div>{/if}

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
    transition: background 0.12s ease;
  }

  .row:hover {
    background: var(--bg);
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

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }
</style>
