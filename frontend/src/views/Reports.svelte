<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    getReportSummary,
    getSalesLeads,
    getWarehouseReport
  } from '../lib/api/reports';
  import type { ReportSummary, SalesLeadsReport, WarehouseReport } from '../lib/api/types';
  import { clearFilters, countActive, loadFilters, saveFilters } from '../lib/filters';
  import Button from '../lib/components/ui/Button.svelte';
  import FiltersModal from '../lib/components/ui/FiltersModal.svelte';
  import Pagination from '../lib/components/ui/Pagination.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import StatusBadge from '../lib/components/StatusBadge.svelte';
  import { formatMinutes } from '../lib/format';
  import { clampPeriod, daysAgoIso, defaultPeriod, isoDate, todayIso } from '../lib/period';

  type ReportTab = 'statuses' | 'managers' | 'clients' | 'warehouses' | 'leads' | 'dynamics';

  const reportTabs: { code: ReportTab; title: string }[] = [
    { code: 'statuses', title: 'По статусам' },
    { code: 'managers', title: 'По менеджерам' },
    { code: 'clients', title: 'По клиентам' },
    { code: 'warehouses', title: 'По складам' },
    { code: 'leads', title: 'Целевые продажи' },
    { code: 'dynamics', title: 'Динамика' }
  ];

  const perPageOptions = [20, 50, 100];

  const filterDefaults = {
    from: defaultPeriod().from,
    to: defaultPeriod().to
  };

  const initialReportFilters = loadFilters('reports', filterDefaults);
  let reportFilters = $state({ ...initialReportFilters });
  let reportDraft = $state({ ...initialReportFilters });
  let summary = $state<ReportSummary | null>(null);
  let leads = $state<SalesLeadsReport | null>(null);
  let warehouseReport = $state<WarehouseReport | null>(null);
  let loading = $state(true);
  let summaryBusy = $state(false);
  let leadsBusy = $state(false);
  let warehousesBusy = $state(false);
  let error = $state('');

  let tab = $state<ReportTab>('statuses');

  let managersPage = $state(1);
  let managersPerPage = $state(20);
  let clientsPage = $state(1);
  let clientsPerPage = $state(20);
  let queriesPage = $state(1);
  let queriesPerPage = $state(20);
  let leadClientsPage = $state(1);
  let leadClientsPerPage = $state(20);
  let dynamicsPage = $state(1);
  let dynamicsPerPage = $state(20);

  function slice<T>(items: T[], page: number, perPage: number): T[] {
    return items.slice((page - 1) * perPage, page * perPage);
  }

  function resetPages(): void {
    managersPage = 1;
    clientsPage = 1;
    queriesPage = 1;
    leadClientsPage = 1;
    dynamicsPage = 1;
  }

  onMount(() => {
    void load();
  });

  function statusShare(count: number): number {
    const total = (summary?.by_status ?? []).reduce((sum, item) => sum + item.count, 0);

    return total > 0 ? Math.round((count / total) * 100) : 0;
  }

  async function loadSummary(): Promise<void> {
    summaryBusy = true;
    error = '';

    try {
      summary = await getReportSummary(reportFilters.from, reportFilters.to);
      resetPages();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить отчёт';
    } finally {
      summaryBusy = false;
      loading = false;
    }
  }

  async function loadLeads(): Promise<void> {
    leadsBusy = true;
    error = '';

    try {
      leads = await getSalesLeads(reportFilters.from, reportFilters.to);
      queriesPage = 1;
      leadClientsPage = 1;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить целевые продажи';
    } finally {
      leadsBusy = false;
    }
  }

  async function loadWarehouseReport(): Promise<void> {
    warehousesBusy = true;
    error = '';

    try {
      warehouseReport = await getWarehouseReport(reportFilters.from, reportFilters.to);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить отчёт по складам';
    } finally {
      warehousesBusy = false;
    }
  }

  async function load(): Promise<void> {
    loading = true;
    await loadSummary();

    if (leads !== null) {
      await loadLeads();
    }

    if (warehouseReport !== null) {
      await loadWarehouseReport();
    }
  }

  async function openTab(next: ReportTab): Promise<void> {
    if (next === tab) {
      await refreshTab();

      return;
    }

    tab = next;

    if (next === 'leads' && leads === null) {
      await loadLeads();
    }

    if (next === 'warehouses' && warehouseReport === null) {
      await loadWarehouseReport();
    }
  }

  async function refreshTab(): Promise<void> {
    if (tab === 'leads') {
      await loadLeads();

      return;
    }

    if (tab === 'warehouses') {
      await loadWarehouseReport();

      return;
    }

    await loadSummary();
  }

  function setPreset(preset: string): void {
    const now = new Date();

    if (preset === 'today') {
      reportDraft.from = todayIso();
      reportDraft.to = todayIso();
    } else if (preset === 'week') {
      reportDraft.from = daysAgoIso(6);
      reportDraft.to = todayIso();
    } else if (preset === 'days30') {
      const period = defaultPeriod();

      reportDraft.from = period.from;
      reportDraft.to = period.to;
    } else if (preset === 'month') {
      reportDraft.from = isoDate(new Date(now.getFullYear(), now.getMonth(), 1));
      reportDraft.to = todayIso();
    } else if (preset === 'prev-month') {
      reportDraft.from = isoDate(new Date(now.getFullYear(), now.getMonth() - 1, 1));
      reportDraft.to = isoDate(new Date(now.getFullYear(), now.getMonth(), 0));
    }
  }

  function applyFilterDraft(): void {
    const period = clampPeriod(reportDraft.from, reportDraft.to);

    reportDraft.from = period.from;
    reportDraft.to = period.to;
    reportFilters = { ...reportDraft };
    saveFilters('reports', reportFilters);
    void load();
  }

  function resetFilters(): void {
    const period = defaultPeriod();
    const next = { ...filterDefaults, from: period.from, to: period.to };

    reportDraft = { ...next };
    reportFilters = { ...next };
    clearFilters('reports');
    void load();
  }
</script>

<section class="page">
  <div class="head">
    <h1>Отчёты</h1>
    <div class="actions">
      <FiltersModal
        count={countActive(reportFilters, filterDefaults)}
        onopen={() => (reportDraft = { ...reportFilters })}
        onapply={applyFilterDraft}
        onreset={resetFilters}
      >
        <div class="presets">
          <Button variant="ghost" onclick={() => setPreset('days30')}>30 дней</Button>
          <Button variant="ghost" onclick={() => setPreset('today')}>Сегодня</Button>
          <Button variant="ghost" onclick={() => setPreset('week')}>7 дней</Button>
          <Button variant="ghost" onclick={() => setPreset('month')}>Этот месяц</Button>
          <Button variant="ghost" onclick={() => setPreset('prev-month')}>Прошлый месяц</Button>
        </div>

        <label class="filter-field">
          <span>Период с</span>
          <input type="date" bind:value={reportDraft.from} max={reportDraft.to} />
        </label>
        <label class="filter-field">
          <span>Период по</span>
          <input type="date" bind:value={reportDraft.to} min={reportDraft.from} max={todayIso()} />
        </label>
      </FiltersModal>
    </div>
  </div>

  {#if error}
    <div class="alert">{error}</div>
  {/if}

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else if summary}
    <div class="tiles">
      <div class="tile">
        <div class="value">{summary.totals.created_in_period}</div>
        <div class="label">Создано за период</div>
      </div>
      <div class="tile">
        <div class="value">{summary.totals.closed_in_period}</div>
        <div class="label">Закрыто за период</div>
      </div>
      <div class="tile">
        <div class="value">{summary.totals.open_now}</div>
        <div class="label">Открыто сейчас</div>
      </div>
      <div class="tile accent">
        <div class="value">{summary.totals.overdue_now}</div>
        <div class="label">Просрочено</div>
      </div>
      <div class="tile">
        <div class="value">{summary.totals.unassigned}</div>
        <div class="label">Без менеджера</div>
      </div>
    </div>

    <div class="report-tabs tab-scroll">
      {#each reportTabs as item (item.code)}
        <button
          type="button"
          class="report-tab"
          class:active={tab === item.code}
          title={tab === item.code ? 'Нажмите, чтобы обновить отчёт' : 'Открыть отчёт'}
          onclick={() => void openTab(item.code)}
        >
          {item.title}
        </button>
      {/each}
    </div>

    {#if tab === 'statuses'}
      <div class="card">
        <h2>По статусам (созданные за период)</h2>
        <div class="status-table">
          <div class="status-head">
            <span>Статус</span>
            <span class="num">Заявок</span>
            <span class="num">Доля</span>
          </div>
          {#each summary.by_status as status}
            <div class="status-row">
              <StatusBadge title={status.title} color={status.color} />
              <span class="num">{status.count}</span>
              <span class="num muted">{statusShare(status.count)}%</span>
            </div>
          {/each}
        </div>
      </div>
    {/if}

    {#if tab === 'managers'}
      <div class="card">
        <h2>По менеджерам</h2>
        {#if summary.by_manager.length === 0}
          <p class="hint">Нет данных за период</p>
        {:else}
          <div class="table">
            <div class="tr th">
              <span>Менеджер</span>
              <span>Создано</span>
              <span>Открыто</span>
              <span>Закрыто</span>
              <span>Просрочено</span>
              <span>Реакция</span>
              <span>Решение</span>
            </div>
            {#each slice(summary.by_manager, managersPage, managersPerPage) as row (row.manager_id)}
              <div class="tr">
                <span class="name">{row.manager_name}</span>
                <span>{row.created_in_period}</span>
                <span>{row.open_now}</span>
                <span>{row.closed_in_period}</span>
                <span class:overdue={row.overdue_now > 0}>{row.overdue_now}</span>
                <span>{formatMinutes(row.avg_response_minutes)}</span>
                <span>{formatMinutes(row.avg_resolution_minutes)}</span>
              </div>
            {/each}
          </div>

          <Pagination
            page={managersPage}
            perPage={managersPerPage}
            total={summary.by_manager.length}
            loading={summaryBusy}
            {perPageOptions}
            onchange={(next) => (managersPage = next)}
            onperpage={(next) => {
              managersPerPage = next;
              managersPage = 1;
            }}
          />
        {/if}
      </div>
    {/if}

    {#if tab === 'clients'}
      <div class="card">
        <h2>По клиентам</h2>
        {#if summary.by_client.length === 0}
          <p class="hint">Нет данных за период</p>
        {:else}
          <div class="table clients">
            <div class="tr th">
              <span>Клиент</span>
              <span>Создано</span>
              <span>Открыто</span>
              <span>Закрыто</span>
              <span>Просрочено</span>
            </div>
            {#each slice(summary.by_client, clientsPage, clientsPerPage) as row (row.client_id)}
              <div class="tr">
                <span class="name">
                  {row.client_name}
                  {#if row.company}<small>{row.company}</small>{/if}
                </span>
                <span>{row.created_in_period}</span>
                <span>{row.open_now}</span>
                <span>{row.closed_in_period}</span>
                <span class:overdue={row.overdue_now > 0}>{row.overdue_now}</span>
              </div>
            {/each}
          </div>

          <Pagination
            page={clientsPage}
            perPage={clientsPerPage}
            total={summary.by_client.length}
            loading={summaryBusy}
            {perPageOptions}
            onchange={(next) => (clientsPage = next)}
            onperpage={(next) => {
              clientsPerPage = next;
              clientsPage = 1;
            }}
          />
        {/if}
      </div>
    {/if}

    {#if tab === 'warehouses'}
      <div class="card">
        <h2>По складам — остатки и реализация</h2>
        <p class="hint">
          Остатки — текущие; заявки, клиенты и запрошенное количество — за период {reportFilters.from} — {reportFilters.to}.
        </p>

        {#if warehousesBusy && warehouseReport === null}
          <div class="center"><Spinner size={22} /></div>
        {:else if warehouseReport}
          <div class="table wh-table">
            <div class="tr th">
              <span>Склад</span>
              <span>Позиций</span>
              <span>Остаток</span>
              <span>Заявок</span>
              <span>Клиентов</span>
              <span>Запрошено</span>
            </div>
            {#each warehouseReport.warehouses as row (row.warehouse_id)}
              <div class="tr">
                <span class="name">{row.warehouse_name}</span>
                <span>
                  {row.positions}
                  {#if row.zero_positions > 0}<small class="wh-zero">+{row.zero_positions} нул.</small>{/if}
                </span>
                <span>{row.stock_quantity.toLocaleString('ru-RU', { maximumFractionDigits: 3 })}</span>
                <span>{row.requests_count}</span>
                <span>{row.clients_count}</span>
                <span>{row.requested_quantity.toLocaleString('ru-RU', { maximumFractionDigits: 3 })}</span>
              </div>
            {/each}
          </div>

          {#if warehouseReport.top_items.length > 0}
            <h3 class="wh-sub">Топ позиций по заявкам и сравнение складов</h3>
            <div class="table wh-top">
              <div class="tr th">
                <span>Позиция</span>
                <span>Запрошено</span>
                <span>Заявок</span>
                <span>По складам</span>
              </div>
              {#each warehouseReport.top_items as item (item.name)}
                <div class="tr">
                  <span class="name">{item.name}</span>
                  <span>{item.total_quantity.toLocaleString('ru-RU', { maximumFractionDigits: 3 })}</span>
                  <span>{item.requests_count}</span>
                  <span class="wh-chips">
                    {#each item.by_warehouse as part (part.warehouse_id)}
                      <span class="wh-chip">
                        {part.warehouse_name}: {part.quantity.toLocaleString('ru-RU', { maximumFractionDigits: 3 })}
                      </span>
                    {/each}
                  </span>
                </div>
              {/each}
            </div>
          {/if}
        {/if}
      </div>
    {/if}

    {#if tab === 'leads'}
      <div class="card">
        <h2>Целевые продажи — действия клиентов на складах</h2>
        <p class="hint">
          Кто и что смотрел и искал, какие запросы остались без результатов — повод предложить
          аналог или оформить заказ.
        </p>

        {#if leadsBusy && leads === null}
          <div class="center"><Spinner size={22} /></div>
        {:else if leads}
          <div class="leads">
            <div class="lead-block">
              <h3>Популярные запросы</h3>
              {#if leads.top_queries.length === 0}
                <p class="hint">Поисков пока не было</p>
              {:else}
                <div class="table leads-table">
                  <div class="tr th"><span>Запрос</span><span>Раз</span><span>Без результата</span></div>
                  {#each slice(leads.top_queries, queriesPage, queriesPerPage) as row (row.query)}
                    <div class="tr">
                      <span class="name">{row.query}</span>
                      <span>{row.count}</span>
                      <span class:overdue={row.zero_results > 0}>{row.zero_results}</span>
                    </div>
                  {/each}
                </div>

                <Pagination
                  page={queriesPage}
                  perPage={queriesPerPage}
                  total={leads.top_queries.length}
                  loading={leadsBusy}
                  {perPageOptions}
                  onchange={(next) => (queriesPage = next)}
                  onperpage={(next) => {
                    queriesPerPage = next;
                    queriesPage = 1;
                  }}
                />
              {/if}
            </div>

            <div class="lead-block">
              <h3>Спрос без наличия (запросы без результатов)</h3>
              {#if leads.zero_result_queries.length === 0}
                <p class="hint">Нет запросов без результатов</p>
              {:else}
                <ul class="zero">
                  {#each leads.zero_result_queries as row (row.query)}
                    <li><span>{row.query}</span><span class="warn">{row.zero_results} раз</span></li>
                  {/each}
                </ul>
              {/if}
            </div>

            <div class="lead-block">
              <h3>Действия клиентов</h3>
              {#if leads.clients.length === 0}
                <p class="hint">Действий нет</p>
              {:else}
                <div class="table leads-table clients-leads">
                  <div class="tr th"><span>Клиент</span><span>Поиски</span><span>Просмотры</span><span>Выгрузки</span></div>
                  {#each slice(leads.clients, leadClientsPage, leadClientsPerPage) as row (row.client_id)}
                    <div class="tr">
                      <span class="name">
                        {row.client_name}
                        {#if row.company}<small>{row.company}</small>{/if}
                      </span>
                      <span>{row.searches}</span>
                      <span>{row.views}</span>
                      <span>{row.exports}</span>
                    </div>
                  {/each}
                </div>

                <Pagination
                  page={leadClientsPage}
                  perPage={leadClientsPerPage}
                  total={leads.clients.length}
                  loading={leadsBusy}
                  {perPageOptions}
                  onchange={(next) => (leadClientsPage = next)}
                  onperpage={(next) => {
                    leadClientsPerPage = next;
                    leadClientsPage = 1;
                  }}
                />
              {/if}
            </div>
          </div>
        {/if}
      </div>
    {/if}

    {#if tab === 'dynamics'}
      <div class="card">
        <h2>Динамика</h2>
        {#if summary.dynamics.length === 0}
          <p class="hint">Нет данных за период</p>
        {:else}
          <div class="dynamics">
            {#each slice(summary.dynamics, dynamicsPage, dynamicsPerPage) as point (point.date)}
              <div class="dyn-row">
                <span class="dyn-date">{point.date}</span>
                <span class="dyn-created">создано {point.created}</span>
                <span class="dyn-closed">закрыто {point.closed}</span>
              </div>
            {/each}
          </div>

          <Pagination
            page={dynamicsPage}
            perPage={dynamicsPerPage}
            total={summary.dynamics.length}
            loading={summaryBusy}
            {perPageOptions}
            onchange={(next) => (dynamicsPage = next)}
            onperpage={(next) => {
              dynamicsPerPage = next;
              dynamicsPage = 1;
            }}
          />
        {/if}
      </div>
    {/if}
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
    margin: 0;
    font-size: 16px;
  }

  @media print {
    .head .actions {
      display: none;
    }

    .page {
      gap: 8px;
    }

    .card,
    .tile {
      break-inside: avoid;
      box-shadow: none;
    }
  }

  .actions {
    display: flex;
    gap: var(--space-2);
  }

  .report-tabs {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .report-tab {
    padding: 7px 16px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--muted);
    cursor: pointer;
    font: inherit;
    font-size: 13px;
  }

  .report-tab.active {
    border-color: var(--primary);
    color: var(--primary);
    background: color-mix(in srgb, var(--primary) 8%, white);
  }

  .presets {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: var(--space-3);
  }

  .tile {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--space-4);
    box-shadow: var(--shadow-sm);
  }

  .tile.accent .value {
    color: var(--danger);
  }

  .value {
    font-size: 26px;
    font-weight: 600;
  }

  .label {
    color: var(--muted);
    font-size: 13px;
  }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--space-5);
    box-shadow: var(--shadow-sm);
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .status-table {
    display: flex;
    flex-direction: column;
    max-width: 640px;
  }

  .status-head,
  .status-row {
    display: grid;
    grid-template-columns: 1fr auto auto;
    align-items: center;
    gap: var(--space-3);
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
  }

  .status-row {
    transition: background 0.12s ease;
  }

  .status-row:hover {
    background: var(--bg);
  }

  .status-head {
    color: var(--muted);
    font-size: 12px;
    border-bottom-width: 2px;
  }

  .status-row .num,
  .status-head .num {
    min-width: 72px;
    text-align: right;
    font-variant-numeric: tabular-nums;
  }

  .status-row .num {
    font-weight: 600;
  }

  .status-row .muted {
    font-weight: 400;
    color: var(--muted);
  }

  .table {
    display: flex;
    flex-direction: column;
  }

  .table.clients .tr {
    grid-template-columns: 2fr repeat(4, 1fr);
  }

  .wh-table .tr {
    grid-template-columns: 2fr repeat(5, 1fr);
  }

  .wh-top .tr {
    grid-template-columns: 2fr 1fr 1fr 3fr;
  }

  .wh-sub {
    margin: var(--space-4) 0 var(--space-2);
    font-size: 15px;
  }

  .wh-zero {
    display: block;
    color: var(--muted);
    font-size: 12px;
  }

  .wh-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
  }

  .wh-chip {
    font-size: 12px;
    padding: 2px 8px;
    border: 1px solid var(--border);
    border-radius: 999px;
    color: var(--muted);
    white-space: nowrap;
  }

  .leads {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .lead-block h3 {
    margin: 0 0 var(--space-2);
    font-size: 14px;
    color: var(--muted);
    font-weight: 500;
  }

  .leads-table .tr {
    grid-template-columns: 2fr repeat(2, 1fr);
  }

  .clients-leads .tr {
    grid-template-columns: 2fr repeat(3, 1fr);
  }

  .zero {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .zero li {
    display: flex;
    justify-content: space-between;
    gap: var(--space-3);
    padding: 6px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 14px;
  }

  .zero .warn {
    color: #b45309;
  }

  .table.clients .name small {
    display: block;
    color: var(--muted);
    font-size: 12px;
  }

  .tr {
    display: grid;
    grid-template-columns: 2fr repeat(6, 1fr);
    gap: var(--space-2);
    padding: var(--space-2) 0;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
    align-items: center;
    transition: background 0.12s ease;
  }

  .tr:not(.th):hover {
    background: var(--bg);
  }

  .tr.th {
    color: var(--muted);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
  }

  .tr:last-child {
    border-bottom: none;
  }

  .name {
    font-weight: 500;
  }

  .overdue {
    color: var(--danger);
    font-weight: 600;
  }

  .dynamics {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
  }

  .dyn-row {
    display: flex;
    gap: var(--space-4);
    font-size: 13px;
  }

  .dyn-date {
    color: var(--muted);
    min-width: 90px;
  }

  .dyn-created {
    color: var(--primary);
  }

  .dyn-closed {
    color: #047857;
  }

  .hint {
    color: var(--muted);
    font-size: 13px;
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

  @media (max-width: 720px) {
    .tr {
      grid-template-columns: 1.5fr repeat(3, 1fr);
    }

    .tr span:nth-child(n + 5) {
      display: none;
    }
  }
</style>
