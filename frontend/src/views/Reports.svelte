<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    downloadReport,
    downloadSalesLeads,
    emailReport,
    emailSalesLeads,
    getReportSummary,
    getSalesLeads
  } from '../lib/api/reports';
  import type { ReportSummary, SalesLeadsReport } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import ExportModal from '../lib/components/ui/ExportModal.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import StatusBadge from '../lib/components/StatusBadge.svelte';
  import { formatMinutes } from '../lib/format';

  function iso(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
  }

  function today(): string {
    return iso(new Date());
  }

  function monthStart(): string {
    const now = new Date();

    return iso(new Date(now.getFullYear(), now.getMonth(), 1));
  }

  let from = $state(monthStart());
  let to = $state(today());
  let summary = $state<ReportSummary | null>(null);
  let leads = $state<SalesLeadsReport | null>(null);
  let loading = $state(true);
  let busy = $state(false);
  let error = $state('');
  let notice = $state('');
  let exportTarget = $state<'report' | 'leads' | null>(null);

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      summary = await getReportSummary(from, to);
      leads = await getSalesLeads(from, to);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить отчёт';
    } finally {
      loading = false;
    }
  }

  function setPreset(preset: string): void {
    const now = new Date();

    if (preset === 'today') {
      from = today();
      to = today();
    } else if (preset === 'week') {
      const start = new Date();
      start.setDate(start.getDate() - 6);
      from = iso(start);
      to = today();
    } else if (preset === 'month') {
      from = monthStart();
      to = today();
    } else if (preset === 'prev-month') {
      from = iso(new Date(now.getFullYear(), now.getMonth() - 1, 1));
      to = iso(new Date(now.getFullYear(), now.getMonth(), 0));
    }

    void load();
  }

  async function exportLeads(format: string, byEmail: boolean): Promise<void> {
    exportTarget = null;
    busy = true;
    error = '';
    notice = '';

    try {
      if (byEmail) {
        const result = await emailSalesLeads(from, to, format);
        notice = `Отчёт «Целевые продажи» отправлен на ${result.email}`;
      } else {
        await downloadSalesLeads(from, to, format);
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось выгрузить отчёт';
    } finally {
      busy = false;
    }
  }

  async function exportFile(format: string, byEmail: boolean): Promise<void> {
    exportTarget = null;
    busy = true;
    error = '';
    notice = '';

    try {
      if (byEmail) {
        const result = await emailReport(format, from, to);
        notice = `Отчёт по заявкам отправлен на ${result.email}`;
      } else {
        await downloadReport(format, from, to);
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось выгрузить отчёт';
    } finally {
      busy = false;
    }
  }
</script>

<section class="page">
  <div class="head">
    <h1>Отчёты</h1>
    <div class="actions">
      <Button variant="ghost" loading={busy} onclick={() => (exportTarget = 'report')}>Экспорт отчёта</Button>
      <Button variant="ghost" loading={busy} onclick={() => (exportTarget = 'leads')}>Целевые продажи</Button>
      <Button variant="ghost" onclick={() => window.print()}>Печать</Button>
    </div>
  </div>

  <div class="filters">
    <div class="presets">
      <Button variant="ghost" onclick={() => setPreset('today')}>Сегодня</Button>
      <Button variant="ghost" onclick={() => setPreset('week')}>7 дней</Button>
      <Button variant="ghost" onclick={() => setPreset('month')}>Этот месяц</Button>
      <Button variant="ghost" onclick={() => setPreset('prev-month')}>Прошлый месяц</Button>
    </div>
    <label class="date">
      <span>с</span>
      <input type="date" bind:value={from} onchange={() => void load()} />
    </label>
    <label class="date">
      <span>по</span>
      <input type="date" bind:value={to} onchange={() => void load()} />
    </label>
  </div>

  {#if error}
    <div class="alert">{error}</div>
  {/if}
  {#if notice}
    <div class="notice">{notice}</div>
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

    <div class="card">
      <h2>По статусам (созданные за период)</h2>
      <div class="statuses">
        {#each summary.by_status as status}
          <div class="status-row">
            <StatusBadge title={status.title} color={status.color} />
            <span class="count">{status.count}</span>
          </div>
        {/each}
      </div>
    </div>

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
          {#each summary.by_manager as row (row.manager_id)}
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
      {/if}
    </div>

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
          {#each summary.by_client as row (row.client_id)}
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
      {/if}
    </div>

    {#if leads}
      <div class="card">
        <h2>Целевые продажи — действия клиентов на складах</h2>
        <p class="hint">
          Кто и что смотрел и искал, какие запросы остались без результатов — повод предложить
          аналог или оформить заказ.
        </p>

        <div class="leads">
          <div class="lead-block">
            <h3>Популярные запросы</h3>
            {#if leads.top_queries.length === 0}
              <p class="hint">Поисков пока не было</p>
            {:else}
              <div class="table leads-table">
                <div class="tr th"><span>Запрос</span><span>Раз</span><span>Без результата</span></div>
                {#each leads.top_queries.slice(0, 12) as row (row.query)}
                  <div class="tr">
                    <span class="name">{row.query}</span>
                    <span>{row.count}</span>
                    <span class:overdue={row.zero_results > 0}>{row.zero_results}</span>
                  </div>
                {/each}
              </div>
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
            <h3>Активность клиентов</h3>
            {#if leads.clients.length === 0}
              <p class="hint">Активности нет</p>
            {:else}
              <div class="table leads-table clients-leads">
                <div class="tr th"><span>Клиент</span><span>Поиски</span><span>Просмотры</span><span>Выгрузки</span></div>
                {#each leads.clients.slice(0, 12) as row (row.client_id)}
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
            {/if}
          </div>
        </div>
      </div>
    {/if}

    {#if summary.dynamics.length > 0}
      <div class="card">
        <h2>Динамика</h2>
        <div class="dynamics">
          {#each summary.dynamics as point (point.date)}
            <div class="dyn-row">
              <span class="dyn-date">{point.date}</span>
              <span class="dyn-created">создано {point.created}</span>
              <span class="dyn-closed">закрыто {point.closed}</span>
            </div>
          {/each}
        </div>
      </div>
    {/if}
  {/if}

  <ExportModal
    open={exportTarget !== null}
    title={exportTarget === 'leads' ? 'Экспорт: целевые продажи' : 'Экспорт отчёта по заявкам'}
    email={auth.user?.email ?? ''}
    onclose={() => (exportTarget = null)}
    onpick={(format, byEmail) =>
      exportTarget === 'leads' ? void exportLeads(format, byEmail) : void exportFile(format, byEmail)}
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
    margin: 0;
    font-size: 16px;
  }

  @media print {
    .head .actions,
    .filters {
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

  .filters {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    align-items: center;
  }

  .presets {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .date {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 13px;
    color: var(--muted);
  }

  .date input {
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
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

  .statuses {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
  }

  .status-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .count {
    font-weight: 600;
  }

  .table {
    display: flex;
    flex-direction: column;
  }

  .table.clients .tr {
    grid-template-columns: 2fr repeat(4, 1fr);
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

  @media (max-width: 720px) {
    .tr {
      grid-template-columns: 1.5fr repeat(3, 1fr);
    }

    .tr span:nth-child(n + 5) {
      display: none;
    }
  }
</style>
