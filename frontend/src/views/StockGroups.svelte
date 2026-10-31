<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { listAdminItemGroups } from '../lib/api/groups';
  import type { ItemGroup } from '../lib/api/types';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  let groups = $state<ItemGroup[]>([]);
  let loading = $state(true);
  let error = $state('');

  const groupsEnabled = $derived(appSettings.groupsEnabled);

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      groups = (await listAdminItemGroups()).items;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить группы';
    } finally {
      loading = false;
    }
  }
</script>

<section class="page">
  <div class="head">
    <button
      type="button"
      class="back"
      title="Назад к номенклатуре"
      aria-label="Назад к номенклатуре"
      onclick={() => router.navigate('/stocks')}
    >
      <Icon name="arrow-left" size={16} />
    </button>
    <div class="head-text">
      <h1>Группы номенклатуры</h1>
      <p class="sub">
        Группа назначается позиции при редактировании; по группам можно фильтровать остатки и выбор
        позиций в заявке.
      </p>
    </div>
    <div class="head-actions">
      <Button onclick={() => router.navigate('/stocks/groups/new')}>Добавить группу</Button>
    </div>
  </div>

  {#if error}
    <div class="alert" role="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span>{error}</span>
    </div>
  {/if}

  {#if !groupsEnabled}
    <div class="notice">
      <span class="notice-icon"><Icon name="info" size={16} /></span>
      <span>Группы номенклатуры отключены — включите модуль в настройках.</span>
      <Button variant="ghost" onclick={() => router.navigate('/settings')}>
        Открыть настройки
      </Button>
    </div>
  {/if}

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else}
    <div class="card">
      {#if groups.length === 0}
        <div class="empty">Групп пока нет — добавьте первую.</div>
      {:else}
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Название</th>
                <th class="count-head">Позиций</th>
                <th class="actions-head">Действия</th>
              </tr>
            </thead>
            <tbody>
              {#each groups as group (group.id)}
                <tr>
                  <td>
                    <div class="name">
                      <span class="title">{group.title}</span>
                      {#if group.description}
                        <span class="desc">{group.description}</span>
                      {/if}
                    </div>
                  </td>
                  <td class="count">{group.positions_count ?? 0}</td>
                  <td>
                    <div class="actions">
                      <Button
                        variant="ghost"
                        onclick={() => router.navigate(`/stocks/groups/${group.id}`)}
                      >
                        Редактировать
                      </Button>
                    </div>
                  </td>
                </tr>
              {/each}
            </tbody>
          </table>
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
    width: 100%;
    max-width: 720px;
    margin: 0 auto;
  }

  .head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: var(--space-3);
  }

  .back {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    flex: 0 0 auto;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
  }

  .back:hover {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .head-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
    color: var(--text);
  }

  .sub {
    margin: 0;
    font-size: 14px;
    color: var(--text-description);
  }

  .head-actions {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex: 0 0 auto;
    margin-left: auto;
  }

  .card {
    padding: var(--space-2) var(--space-4);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
  }

  .table-wrap {
    overflow-x: auto;
  }

  table {
    width: 100%;
    min-width: 520px;
    border-collapse: collapse;
  }

  th {
    padding: 10px 12px;
    border-bottom: 2px solid var(--border);
    color: var(--text-description);
    font-size: 12px;
    font-weight: 500;
    text-align: left;
    white-space: nowrap;
  }

  th.count-head {
    width: 120px;
  }

  th.actions-head {
    text-align: right;
  }

  td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
    vertical-align: middle;
  }

  tbody tr:last-child td {
    border-bottom: none;
  }

  tbody tr:hover {
    background: var(--fill-tertiary);
  }

  .name {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .title {
    font-weight: 500;
    overflow-wrap: anywhere;
  }

  .desc {
    color: var(--text-description);
    font-size: 13px;
    overflow-wrap: anywhere;
  }

  .count {
    color: var(--text-description);
    white-space: nowrap;
  }

  .actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
    white-space: nowrap;
  }

  .empty {
    padding: var(--space-5);
    border: 1px dashed var(--border-secondary);
    border-radius: var(--radius-md);
    color: var(--text-description);
    font-size: 14px;
    text-align: center;
  }

  .notice,
  .alert {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    color: var(--text);
    font-size: 14px;
  }

  .notice {
    border-color: var(--success-border);
    background: var(--success-bg);
  }

  .alert {
    border-color: var(--error-border);
    background: var(--danger-bg);
  }

  .notice-icon,
  .alert-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
  }

  .notice-icon {
    color: var(--success);
  }

  .alert-icon {
    color: var(--danger);
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }
</style>
