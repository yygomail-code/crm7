<script lang="ts">
  import { ApiError } from '../lib/api/client';
  import {
    createItemGroup,
    deleteItemGroup,
    listAdminItemGroups,
    listGroupItems,
    updateItemGroup
  } from '../lib/api/groups';
  import type { ItemGroup, ItemGroupItem } from '../lib/api/types';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Input from '../lib/components/ui/Input.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  let { id }: { id: number } = $props();

  const isNew = $derived(id <= 0);
  const groupsEnabled = $derived(appSettings.groupsEnabled);
  const TYPE_TITLES: Record<string, string> = { product: 'Товар', service: 'Услуга', set: 'Набор' };

  let group = $state<ItemGroup | null>(null);
  let items = $state<ItemGroupItem[]>([]);
  let title = $state('');
  let description = $state('');
  let loading = $state(true);
  let saving = $state(false);
  let deleting = $state(false);
  let error = $state('');
  let itemsError = $state('');
  let message = $state('');

  function typeTitle(code: string): string {
    return TYPE_TITLES[code] ?? code;
  }

  $effect(() => {
    void id;
    void load();
  });

  async function load(): Promise<void> {
    error = '';
    itemsError = '';
    items = [];

    if (isNew) {
      loading = false;
      return;
    }

    loading = true;

    try {
      const found = (await listAdminItemGroups()).items.find((item) => item.id === id) ?? null;

      if (found === null) {
        error = 'Группа не найдена';
        return;
      }

      group = found;
      title = found.title;
      description = found.description ?? '';

      try {
        items = (await listGroupItems(id)).items;
      } catch (cause) {
        itemsError =
          cause instanceof ApiError ? cause.message : 'Не удалось загрузить состав группы';
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить группу';
    } finally {
      loading = false;
    }
  }

  async function save(): Promise<void> {
    const trimmedTitle = title.trim();

    error = '';
    message = '';

    if (trimmedTitle === '') {
      error = 'Укажите название группы';
      return;
    }

    saving = true;

    try {
      if (isNew) {
        await createItemGroup(trimmedTitle, description.trim());
        router.navigate('/stocks/groups');
        return;
      }

      await updateItemGroup(id, trimmedTitle, description.trim());
      await load();
      message = 'Изменения сохранены';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить группу';
    } finally {
      saving = false;
    }
  }

  async function removeGroup(): Promise<void> {
    if (isNew || group === null) {
      return;
    }

    if (!confirm(`Удалить группу «${group.title}»? Позиции останутся без группы.`)) {
      return;
    }

    deleting = true;
    error = '';
    message = '';

    try {
      await deleteItemGroup(id);
      router.navigate('/stocks/groups');
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось удалить группу';
    } finally {
      deleting = false;
    }
  }
</script>

<section class="page">
  <div class="head">
    <button
      type="button"
      class="back"
      title="Назад к группам"
      aria-label="Назад к группам"
      onclick={() => router.navigate('/stocks/groups')}
    >
      <Icon name="arrow-left" size={16} />
    </button>
    <div class="head-text">
      <h1>{isNew ? 'Новая группа' : (group?.title ?? 'Группа')}</h1>
      <p class="sub">
        {isNew
          ? 'Заполните название и описание группы.'
          : 'Изменения названия и описания сохраняются отдельно.'}
      </p>
    </div>
    {#if !isNew}
      <div class="head-actions">
        <Button
          variant="danger"
          loading={deleting}
          disabled={saving}
          onclick={() => void removeGroup()}
        >
          Удалить
        </Button>
      </div>
    {/if}
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
  {:else if !isNew && group === null}
    <div class="empty">Группа не найдена.</div>
  {:else}
    <div class="card">
      <div class="form">
        <Input
          label="Название"
          bind:value={title}
          placeholder="Например: Смартфоны"
          disabled={saving}
        />

        <label class="field">
          <span class="label">Описание</span>
          <textarea
            bind:value={description}
            rows="4"
            maxlength="1000"
            placeholder="Коротко: что входит в группу"
            disabled={saving}
          ></textarea>
        </label>

        <div class="actions">
          <Button variant="ghost" disabled={saving} onclick={() => router.navigate('/stocks/groups')}>
            Отмена
          </Button>
          <Button loading={saving} disabled={!title.trim()} onclick={() => void save()}>
            {isNew ? 'Создать группу' : 'Сохранить'}
          </Button>
        </div>
      </div>
    </div>

    {#if !isNew && group !== null}
      <div class="card">
        <div class="card-head">
          <h2>Состав группы</h2>
          <span class="count">{items.length} поз.</span>
        </div>

        {#if itemsError}
          <div class="alert" role="alert">
            <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
            <span>{itemsError}</span>
          </div>
        {:else if items.length === 0}
          <div class="empty">В группе пока нет позиций. Назначьте группу в карточке позиции.</div>
        {:else}
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Название</th>
                  <th>Артикул</th>
                  <th>Тип</th>
                  <th>Ед.</th>
                </tr>
              </thead>
              <tbody>
                {#each items as item (item.sid)}
                  <tr>
                    <td class="item-name">
                      {item.name}
                      {#if !item.active}<span class="tag">деактивирована</span>{/if}
                    </td>
                    <td>{item.article || '—'}</td>
                    <td>{typeTitle(item.type)}</td>
                    <td>{item.unit || '—'}</td>
                  </tr>
                {/each}
              </tbody>
            </table>
          </div>
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
    width: 100%;
    max-width: 720px;
    margin-inline: auto;
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
    overflow-wrap: anywhere;
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
    padding: var(--space-4);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
  }

  .card-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-2);
    margin-bottom: var(--space-3);
  }

  h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
  }

  .count {
    color: var(--text-description);
    font-size: 13px;
    white-space: nowrap;
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
    padding: 8px 12px;
    border-bottom: 2px solid var(--border);
    color: var(--text-description);
    font-size: 12px;
    font-weight: 500;
    text-align: left;
    white-space: nowrap;
  }

  td {
    padding: 8px 12px;
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

  .item-name {
    font-weight: 500;
    overflow-wrap: anywhere;
  }

  .tag {
    margin-left: var(--space-2);
    padding: 0 7px;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: var(--fill-tertiary);
    color: var(--text-description);
    font-size: 12px;
    font-weight: 400;
    line-height: 20px;
  }

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .field {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
  }

  .label {
    font-size: 14px;
    color: var(--text-description);
  }

  textarea {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    outline: none;
    resize: vertical;
  }

  textarea:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--focus-ring);
  }

  textarea:disabled {
    background: var(--fill-tertiary);
    color: var(--text-description);
    cursor: not-allowed;
  }

  .actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
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
