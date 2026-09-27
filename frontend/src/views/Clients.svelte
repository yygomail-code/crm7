<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { pendingClients } from '../lib/api/clients';
  import { listClients } from '../lib/api/requests';
  import type { ClientItem, PendingClient } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import FiltersModal from '../lib/components/ui/FiltersModal.svelte';
  import Pagination from '../lib/components/ui/Pagination.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { clearFilters, countActive, loadFilters, saveFilters } from '../lib/filters';
  import { addHistory } from '../lib/search-history';

  const filterDefaults = {
    state: '',
    manager: '',
    from: '',
    to: '',
    sort: 'name_asc'
  };

  const filterCountDefaults = {
    state: filterDefaults.state,
    manager: filterDefaults.manager,
    from: filterDefaults.from,
    to: filterDefaults.to
  };

  let clients = $state<ClientItem[]>([]);
  let total = $state(0);
  let page = $state(1);
  let perPage = $state(20);
  let pending = $state<PendingClient[]>([]);
  let loading = $state(true);
  let error = $state('');
  let search = $state('');
  let searchInput = $state('');
  const initialFilters = loadFilters('clients', filterDefaults);
  let filters = $state({ ...initialFilters });
  let draft = $state({ ...initialFilters });

  const canConfirm = $derived(auth.can('clients.confirm'));

  const sortOptions = [
    { code: 'name_asc', title: 'По имени (А → Я)' },
    { code: 'name_desc', title: 'По имени (Я → А)' },
    { code: 'created_desc', title: 'Сначала новые' },
    { code: 'created_asc', title: 'Сначала старые' },
    { code: 'requests_desc', title: 'Больше заявок' },
    { code: 'requests_asc', title: 'Меньше заявок' }
  ];

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      const data = await listClients(search, page, perPage, {
        state: filters.state || undefined,
        manager: filters.manager || undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
        sort: filters.sort
      });
      clients = data.items;
      total = data.total;

      if (canConfirm) {
        pending = (await pendingClients()).items;
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить клиентов';
    } finally {
      loading = false;
    }
  }

  function goToPage(next: number): void {
    page = next;
    void load();
  }

  function changePerPage(value: number): void {
    perPage = value;
    page = 1;
    void load();
  }

  function applySearch(): void {
    page = 1;
    void load();
  }

  function submitSearch(): void {
    search = searchInput.trim();
    addHistory('clients', search);
    applySearch();
  }

  function resetSearch(): void {
    searchInput = '';
    search = '';
    applySearch();
  }

  function applyFilterDraft(): void {
    filters = { ...draft };
    saveFilters('clients', filters);
    page = 1;
    void load();
  }

  function resetFilters(): void {
    draft = { ...filterDefaults };
    filters = { ...filterDefaults };
    clearFilters('clients');
    page = 1;
    void load();
  }

  function changeSort(): void {
    saveFilters('clients', filters);
    page = 1;
    void load();
  }
</script>

<section class="page">
  <div class="head">
    <h1>Клиенты</h1>
  </div>

  <div class="search-row">
    <form class="search" onsubmit={(event) => { event.preventDefault(); submitSearch(); }}>
      <SearchInput
        bind:value={searchInput}
        historyKey="clients"
        placeholder="Поиск: имя, компания, ИНН, телефон"
        onclear={() => {
          search = '';
          applySearch();
        }}
        onpick={submitSearch}
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
        <span>Состояние</span>
        <select bind:value={draft.state}>
          <option value="">Все состояния</option>
          <option value="active">Активные</option>
          <option value="pending">Ожидают подтверждения</option>
          <option value="blocked">Заблокированные</option>
        </select>
      </label>

      <label class="filter-field">
        <span>Менеджер</span>
        <select bind:value={draft.manager}>
          <option value="">Все менеджеры</option>
          <option value="mine">Мои клиенты</option>
          <option value="none">Без менеджера</option>
        </select>
      </label>

      <label class="filter-field">
        <span>Регистрация с</span>
        <input type="date" bind:value={draft.from} max={draft.to} />
      </label>
      <label class="filter-field">
        <span>Регистрация по</span>
        <input type="date" bind:value={draft.to} min={draft.from} />
      </label>
    </FiltersModal>

    <label class="sort-field">
      <span>Сортировка</span>
      <select bind:value={filters.sort} onchange={changeSort}>
        {#each sortOptions as option (option.code)}
          <option value={option.code}>{option.title}</option>
        {/each}
      </select>
    </label>
  </div>

  <div class="legend">
    <span class="legend-item"><span class="dot pending"></span>ожидает подтверждения регистрации</span>
    <span class="legend-item"><span class="dot blocked"></span>заблокирован</span>
    <span class="legend-item"><span class="dot transfer"></span>передают вам</span>
  </div>

  {#if error}
    <div class="alert">{error}</div>
  {/if}

  {#if canConfirm && pending.length > 0}
    <div class="pending">
      <h2>Ожидают подтверждения <span class="count">{pending.length}</span></h2>
      <p class="hint">Откройте карточку клиента — подтверждение и отклонение там</p>
      <div class="list">
        {#each pending as item (item.id)}
          <div class="card pending-card">
            <div class="info">
              <a class="name link" href={`#/clients/${item.id}`}>{item.name}</a>
              <div class="meta">
                {#if item.company}<span>{item.company}</span>{/if}
                {#if item.inn}<span>ИНН {item.inn}</span>{/if}
                {#if item.phone}<span>{item.phone}</span>{/if}
                {#if item.email}<span>{item.email}</span>{/if}
              </div>
              <div class="manager">Зарегистрировался: {item.registered_at}</div>
            </div>
            <span class="state">ожидает подтверждения</span>
          </div>
        {/each}
      </div>
    </div>
  {/if}

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else if clients.length === 0}
    <div class="empty">Клиенты не найдены</div>
  {:else}
    <div class="list">
      {#each clients as client (client.id)}
        <div class="card" class:pending={client.reg_state === 'pending'} class:blocked={!client.active}>
          <div class="info">
            <div class="name-row">
              <a class="name link" href={`#/clients/${client.id}`}>{client.name}</a>
              {#if client.reg_state === 'pending'}
                <span class="state">ожидает подтверждения</span>
              {/if}
              {#if !client.active}
                <span class="state blocked-chip">заблокирован</span>
              {/if}
              {#if client.transfer_to_me}
                <span class="state transfer-chip">→ вам передают</span>
              {/if}
            </div>
            <div class="meta">
              {#if client.company}<span>{client.company}</span>{/if}
              {#if client.inn}<span>ИНН {client.inn}</span>{/if}
              {#if client.phone}<span>{client.phone}</span>{/if}
              {#if client.email}<span>{client.email}</span>{/if}
            </div>
            <div class="manager">
              {#if client.manager}
                менеджер: {client.manager.name}
              {:else}
                без менеджера
              {/if}
              · заявок: {client.requests_total}
              {#if client.registered_at}· зарегистрирован: {client.registered_at.slice(0, 10)}{/if}
            </div>
          </div>
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
    margin: 0 0 var(--space-3);
    font-size: 16px;
  }

  .pending .count {
    display: inline-block;
    min-width: 20px;
    padding: 1px 8px;
    margin-left: 4px;
    border-radius: 999px;
    background: var(--primary);
    color: #fff;
    font-size: 12px;
    text-align: center;
  }

  .pending-card,
  .card.pending {
    border-color: color-mix(in srgb, var(--primary) 35%, var(--border));
    background: color-mix(in srgb, var(--primary) 6%, var(--surface));
  }

  .card.blocked {
    border-color: color-mix(in srgb, var(--danger) 45%, var(--border));
    background: color-mix(in srgb, var(--danger) 6%, var(--surface));
  }

  .name-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .state.blocked-chip {
    border-color: color-mix(in srgb, var(--danger) 45%, var(--border));
    color: var(--danger);
  }

  .state.transfer-chip {
    border-color: color-mix(in srgb, var(--primary) 45%, var(--border));
    color: var(--primary);
  }

  .legend {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-4);
    font-size: 12px;
    color: var(--muted);
  }

  .legend-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .dot {
    width: 10px;
    height: 10px;
    border-radius: 3px;
    border: 1px solid var(--border);
  }

  .dot.pending {
    background: color-mix(in srgb, var(--primary) 35%, #fff);
    border-color: var(--primary);
  }

  .dot.blocked {
    background: color-mix(in srgb, var(--danger) 30%, #fff);
    border-color: var(--danger);
  }

  .dot.transfer {
    background: color-mix(in srgb, var(--primary) 55%, #fff);
    border-color: var(--primary);
  }

  .hint {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
  }

  .state {
    font-size: 12px;
    padding: 3px 10px;
    border-radius: 999px;
    border: 1px solid var(--border);
    color: var(--muted);
    white-space: nowrap;
  }

  .link {
    color: inherit;
    text-decoration: none;
    border-bottom: 1px dashed var(--border);
  }

  .link:hover {
    border-bottom-color: var(--primary);
  }

  .search-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2);
  }

  .search {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    flex: 1 1 auto;
    min-width: 0;
    max-width: none;
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

  .sort-field select {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    color: var(--text);
  }

  @media (max-width: 720px) {
    .search {
      flex: 1 1 100%;
      max-width: none;
    }
  }

  .list {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .card {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    transition: background 0.12s ease, border-color 0.12s ease;
  }

  .card:hover {
    background: var(--bg);
    border-color: var(--primary);
  }

  .info {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .name {
    font-size: 16px;
    font-weight: 500;
  }

  .meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    font-size: 13px;
    color: var(--muted);
  }

  .manager {
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
