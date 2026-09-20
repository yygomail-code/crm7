<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { activateClient, pendingClients, rejectClient } from '../lib/api/clients';
  import { assignClient, listClients, listManagers } from '../lib/api/requests';
  import type { ClientItem, ManagerItem, PendingClient } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Pagination from '../lib/components/ui/Pagination.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { addHistory } from '../lib/search-history';

  let clients = $state<ClientItem[]>([]);
  let total = $state(0);
  let page = $state(1);
  const perPage = 20;
  let managers = $state<ManagerItem[]>([]);
  let pending = $state<PendingClient[]>([]);
  let selected = $state<Record<number, number>>({});
  let loading = $state(true);
  let error = $state('');
  let message = $state('');
  let busyId = $state(0);
  let search = $state('');
  let searchInput = $state('');

  const canAssign = $derived(auth.can('clients.assign'));
  const canConfirm = $derived(auth.can('clients.confirm'));

  onMount(() => {
    if (canAssign) {
      void listManagers()
        .then((data) => {
          managers = data.items;
        })
        .catch(() => {
          managers = [];
        });
    }

    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      const data = await listClients(search, page, perPage);
      clients = data.items;
      total = data.total;
      selected = {};

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

  function applySearch(): void {
    page = 1;
    void load();
  }

  function submitSearch(): void {
    search = searchInput.trim();
    addHistory('clients', search);
    applySearch();
  }

  async function approve(item: PendingClient): Promise<void> {
    busyId = item.id;
    error = '';
    message = '';

    try {
      await activateClient(item.id, '');
      message = `${item.name}: регистрация подтверждена`;
      await load();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось подтвердить регистрацию';
    } finally {
      busyId = 0;
    }
  }

  async function decline(item: PendingClient): Promise<void> {
    const reason = prompt('Причина отказа (уйдёт клиенту в письме):', '');

    if (reason === null) {
      return;
    }

    if (reason.trim() === '') {
      error = 'Укажите причину отказа';
      return;
    }

    busyId = item.id;
    error = '';
    message = '';

    try {
      await rejectClient(item.id, reason.trim());
      message = `${item.name}: регистрация отклонена`;
      await load();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось отклонить регистрацию';
    } finally {
      busyId = 0;
    }
  }

  async function assign(client: ClientItem): Promise<void> {
    const managerId = selected[client.id];

    if (!managerId) return;

    busyId = client.id;
    error = '';

    try {
      const result = await assignClient(client.id, managerId);
      clients = clients.map((item) =>
        item.id === client.id
          ? {
              ...item,
              manager: {
                manager_id: result.manager.id,
                manager_name: result.manager.name,
                is_primary: true
              }
            }
          : item
      );
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось назначить менеджера';
    } finally {
      busyId = 0;
    }
  }
</script>

<section class="page">
  <div class="head">
    <h1>Клиенты</h1>
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
  </div>

  {#if error}
    <div class="alert">{error}</div>
  {/if}

  {#if message}
    <div class="notice">{message}</div>
  {/if}

  {#if canConfirm && pending.length > 0}
    <div class="pending">
      <h2>Ожидают подтверждения <span class="count">{pending.length}</span></h2>
      <div class="list">
        {#each pending as item (item.id)}
          <div class="card pending-card">
            <div class="info">
              <div class="name">{item.name}</div>
              <div class="meta">
                {#if item.company}<span>{item.company}</span>{/if}
                {#if item.inn}<span>ИНН {item.inn}</span>{/if}
                {#if item.phone}<span>{item.phone}</span>{/if}
                {#if item.email}<span>{item.email}</span>{/if}
              </div>
              <div class="manager">Зарегистрировался: {item.registered_at}</div>
            </div>
            <div class="assign">
              <Button loading={busyId === item.id} onclick={() => void approve(item)}>
                Подтвердить
              </Button>
              <Button variant="ghost" loading={busyId === item.id} onclick={() => void decline(item)}>
                Отклонить
              </Button>
            </div>
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
        <div class="card">
          <div class="info">
            <a class="name link" href={`#/clients/${client.id}`}>{client.name}</a>
            <div class="meta">
              {#if client.company}<span>{client.company}</span>{/if}
              {#if client.inn}<span>ИНН {client.inn}</span>{/if}
              {#if client.phone}<span>{client.phone}</span>{/if}
              {#if client.email}<span>{client.email}</span>{/if}
            </div>
            <div class="manager">
              Менеджер: {client.manager?.manager_name ?? 'не назначен'}
            </div>
          </div>

          {#if canAssign}
            <div class="assign">
              <select bind:value={selected[client.id]}>
                <option value={undefined}>Выберите менеджера</option>
                {#each managers as manager}
                  <option value={manager.id}>{manager.name}</option>
                {/each}
              </select>
              <Button
                variant="ghost"
                loading={busyId === client.id}
                disabled={!selected[client.id]}
                onclick={() => void assign(client)}
              >
                Назначить
              </Button>
            </div>
          {/if}
        </div>
      {/each}
    </div>

    <Pagination page={page} perPage={perPage} total={total} loading={loading} onchange={goToPage} />
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

  .pending-card {
    border-color: color-mix(in srgb, var(--primary) 35%, var(--border));
  }

  .notice {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: #e7f5ec;
    color: #1e6b3a;
    font-size: 13px;
  }

  .link {
    color: inherit;
    text-decoration: none;
    border-bottom: 1px dashed var(--border);
  }

  .link:hover {
    border-bottom-color: var(--primary);
  }

  .search {
    display: flex;
    gap: var(--space-2);
    flex: 1;
    min-width: 220px;
    max-width: 420px;
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

  .assign {
    display: flex;
    gap: var(--space-2);
    align-items: center;
  }

  select {
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
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
