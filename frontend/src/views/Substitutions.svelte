<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { listManagers } from '../lib/api/requests';
  import { createSubstitution, endSubstitution, listSubstitutions } from '../lib/api/substitutions';
  import type { ManagerItem, Substitution } from '../lib/api/types';
  import Button from '../lib/components/ui/Button.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { auth } from '../lib/stores/auth.svelte';

  function iso(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
  }

  function today(): string {
    return iso(new Date());
  }

  let items = $state<Substitution[]>([]);
  let managers = $state<ManagerItem[]>([]);
  let loading = $state(true);
  let busy = $state(false);
  let error = $state('');
  let message = $state('');

  const canAssign = $derived(auth.can('clients.assign'));

  let managerId = $state<number | null>(null);
  let substituteId = $state<number | null>(null);
  let dateFrom = $state(today());
  let dateTo = $state(today());
  let reason = $state('');

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      items = await listSubstitutions();

      if (canAssign && managers.length === 0) {
        managers = (await listManagers()).items;
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить замещения';
    } finally {
      loading = false;
    }
  }

  async function submit(): Promise<void> {
    message = '';
    error = '';

    if (managerId === null || substituteId === null) {
      error = 'Выберите менеджера и заместителя';
      return;
    }

    busy = true;

    try {
      const result = await createSubstitution({
        manager_id: managerId,
        substitute_id: substituteId,
        date_from: dateFrom,
        date_to: dateTo,
        reason
      });

      message =
        result.moved > 0
          ? `Замещение создано, передано заявок: ${result.moved}`
          : 'Замещение создано';

      managerId = null;
      substituteId = null;
      reason = '';
      items = await listSubstitutions();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось создать замещение';
    } finally {
      busy = false;
    }
  }

  async function finish(item: Substitution): Promise<void> {
    const question =
      item.date_from > today()
        ? 'Удалить замещение? Оно ещё не началось.'
        : `Завершить замещение и вернуть заявки менеджеру (${item.requests_count})?`;

    if (!confirm(question)) {
      return;
    }

    busy = true;
    message = '';
    error = '';

    try {
      const result = await endSubstitution(item.id);

      message = result.deleted
        ? 'Замещение удалено'
        : `Замещение завершено, возвращено заявок: ${result.returned}`;

      items = await listSubstitutions();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось завершить замещение';
    } finally {
      busy = false;
    }
  }
</script>

<section class="page">
  <div class="head">
    <h1>Замещения</h1>
    <Button variant="ghost" loading={loading} onclick={() => void load()}>Обновить</Button>
  </div>

  {#if error}<p class="alert error">{error}</p>{/if}
  {#if message}<p class="alert ok">{message}</p>{/if}

  {#if canAssign}
    <div class="card form">
      <h2>Новое замещение</h2>
      <p class="hint">
        На время отпуска открытые заявки менеджера передаются заместителю и автоматически
        возвращаются, когда период закончится.
      </p>

      <div class="grid">
        <label>
          <span>Кто в отпуске</span>
          <select bind:value={managerId}>
            <option value={null}>— выберите —</option>
            {#each managers as manager (manager.id)}
              <option value={manager.id}>{manager.name}</option>
            {/each}
          </select>
        </label>

        <label>
          <span>Заместитель</span>
          <select bind:value={substituteId}>
            <option value={null}>— выберите —</option>
            {#each managers as manager (manager.id)}
              <option value={manager.id}>{manager.name}</option>
            {/each}
          </select>
        </label>

        <label>
          <span>С</span>
          <input type="date" bind:value={dateFrom} />
        </label>

        <label>
          <span>По</span>
          <input type="date" bind:value={dateTo} />
        </label>
      </div>

      <label class="wide">
        <span>Причина (необязательно)</span>
        <input type="text" bind:value={reason} placeholder="Отпуск, больничный, командировка" />
      </label>

      <div class="actions">
        <Button loading={busy} onclick={() => void submit()}>Создать замещение</Button>
      </div>
    </div>
  {/if}

  {#if loading}
    <div class="center"><Spinner /></div>
  {:else if items.length === 0}
    <p class="empty">Замещений нет.</p>
  {:else}
    <div class="list">
      {#each items as item (item.id)}
        <div class="card item" class:inactive={!item.is_active}>
          <div class="row">
            <div class="who">
              <strong>{item.manager.name}</strong>
              <span class="arrow">→</span>
              <strong>{item.substitute.name}</strong>
            </div>
            <span class="badge" class:on={item.is_active}>{item.is_active ? 'активно' : 'завершено'}</span>
          </div>

          <div class="meta">
            <span>{item.date_from} — {item.date_to}</span>
            {#if item.requests_count > 0}
              <span>заявок у заместителя: {item.requests_count}</span>
            {/if}
            {#if item.reason}<span>{item.reason}</span>{/if}
          </div>

          {#if canAssign && item.is_active}
            <div class="actions">
              <Button variant="ghost" loading={busy} onclick={() => void finish(item)}>
                {item.date_from > today() ? 'Удалить' : 'Завершить'}
              </Button>
            </div>
          {/if}
        </div>
      {/each}
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
  }

  h1 {
    font-size: 22px;
    margin: 0;
  }

  h2 {
    font-size: 16px;
    margin: 0;
  }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: var(--space-4);
  }

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .hint {
    margin: 0;
    font-size: 13px;
    color: var(--muted);
  }

  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--space-3);
  }

  label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 13px;
    color: var(--muted);
  }

  select,
  input {
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg, #fff);
    color: var(--text);
    font-size: 14px;
    width: 100%;
  }

  .actions {
    display: flex;
    gap: var(--space-2);
  }

  .alert {
    margin: 0;
    padding: 10px 12px;
    border-radius: 8px;
    font-size: 14px;
  }

  .alert.error {
    background: #fdecea;
    color: #8c1d18;
  }

  .alert.ok {
    background: #e7f5ec;
    color: #1e6b3a;
  }

  .list {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .item {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .item.inactive {
    opacity: 0.65;
  }

  .row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
  }

  .who {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .arrow {
    color: var(--muted);
  }

  .badge {
    font-size: 12px;
    padding: 2px 10px;
    border-radius: 999px;
    border: 1px solid var(--border);
    color: var(--muted);
  }

  .badge.on {
    border-color: #1e6b3a;
    color: #1e6b3a;
  }

  .meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    font-size: 13px;
    color: var(--muted);
  }

  .center {
    display: flex;
    justify-content: center;
    padding: var(--space-5);
  }

  .empty {
    color: var(--muted);
    text-align: center;
    padding: var(--space-5);
  }
</style>
