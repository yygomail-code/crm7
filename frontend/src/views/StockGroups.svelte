<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    createItemGroup,
    deleteItemGroup,
    listAdminItemGroups,
    updateItemGroup
  } from '../lib/api/groups';
  import type { ItemGroup } from '../lib/api/types';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  let groups = $state<ItemGroup[]>([]);
  let drafts = $state<Record<number, string>>({});
  let newTitle = $state('');
  let loading = $state(true);
  let busy = $state(false);
  let error = $state('');
  let message = $state('');

  const groupsEnabled = $derived(appSettings.groupsEnabled);

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      const data = await listAdminItemGroups();
      groups = data.items;
      drafts = Object.fromEntries(data.items.map((group) => [group.id, group.title]));
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить группы';
    } finally {
      loading = false;
    }
  }

  async function addGroup(): Promise<void> {
    const title = newTitle.trim();

    if (title === '') {
      return;
    }

    busy = true;
    error = '';
    message = '';

    try {
      await createItemGroup(title);
      newTitle = '';
      await load();
      message = 'Группа добавлена';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось добавить группу';
    } finally {
      busy = false;
    }
  }

  async function saveGroup(group: ItemGroup): Promise<void> {
    const title = (drafts[group.id] ?? '').trim();

    if (title === '') {
      error = 'Укажите название группы';
      return;
    }

    busy = true;
    error = '';
    message = '';

    try {
      await updateItemGroup(group.id, title);
      await load();
      message = 'Группа сохранена';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить группу';
    } finally {
      busy = false;
    }
  }

  async function removeGroup(group: ItemGroup): Promise<void> {
    if (!confirm(`Удалить группу «${group.title}»? Позиции останутся без группы.`)) {
      return;
    }

    busy = true;
    error = '';
    message = '';

    try {
      await deleteItemGroup(group.id);
      await load();
      message = 'Группа удалена';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось удалить группу';
    } finally {
      busy = false;
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
  </div>

  {#if error}
    <div class="alert" role="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span>{error}</span>
    </div>
  {/if}
  {#if message}
    <div class="notice">
      <span class="notice-icon"><Icon name="check-circle" size={16} /></span>
      <span>{message}</span>
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
      <h2>Группы позиций</h2>

      {#if groups.length === 0}
        <p class="hint">Групп пока нет — добавьте первую.</p>
      {:else}
        <div class="groups">
          {#each groups as group (group.id)}
            <div class="group-row">
              <input class="group-title" bind:value={drafts[group.id]} maxlength="255" />
              <span class="group-meta">{group.positions_count ?? 0} поз.</span>
              <Button variant="ghost" disabled={busy} onclick={() => void saveGroup(group)}>
                Сохранить
              </Button>
              <button
                type="button"
                class="group-del"
                disabled={busy}
                onclick={() => void removeGroup(group)}
              >
                Удалить
              </button>
            </div>
          {/each}
        </div>
      {/if}

      <div class="group-add">
        <label>
          <span>Новая группа</span>
          <input bind:value={newTitle} placeholder="Например: Смартфоны" maxlength="255" />
        </label>
        <Button
          variant="ghost"
          loading={busy}
          disabled={!newTitle.trim()}
          onclick={() => void addGroup()}
        >
          Добавить группу
        </Button>
      </div>
    </div>
  {/if}
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    width: 100%;
    max-width: 1280px;
    margin-inline: auto;
  }

  .head {
    display: flex;
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

  .card {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-4);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
  }

  h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
  }

  .hint {
    margin: 0;
    font-size: 13px;
    color: var(--text-description);
  }

  .groups {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .group-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-2) var(--space-3);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
  }

  .group-title {
    flex: 1;
    min-width: 180px;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    outline: none;
  }

  .group-title:focus {
    border-color: var(--primary);
  }

  .group-meta {
    color: var(--muted);
    font-size: 12px;
    white-space: nowrap;
  }

  .group-del {
    padding: 9px 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--danger);
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
  }

  .group-del:hover:not(:disabled) {
    border-color: var(--danger);
    background: var(--danger-bg);
  }

  .group-del:disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }

  .group-add {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: var(--space-2);
    padding-top: var(--space-3);
    border-top: 1px solid var(--border-secondary);
  }

  .group-add label {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: var(--space-1);
    min-width: 200px;
    font-size: 14px;
    color: var(--text-description);
  }

  .group-add input {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    outline: none;
  }

  .group-add input:focus {
    border-color: var(--primary);
  }

  .notice,
  .alert {
    display: flex;
    align-items: flex-start;
    flex-wrap: wrap;
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
