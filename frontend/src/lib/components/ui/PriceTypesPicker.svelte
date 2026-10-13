<script lang="ts">
  import { onMount } from 'svelte';
  import Modal from './Modal.svelte';
  import Button from './Button.svelte';
  import Spinner from './Spinner.svelte';
  import { ApiError } from '../../api/client';
  import { listPriceTypes, setUserPriceTypes } from '../../api/prices';
  import type { AssignedPriceType, PriceType } from '../../api/types';

  interface Props {
    open: boolean;
    userId: number;
    userName?: string;
    assigned: AssignedPriceType[];
    onclose: () => void;
    onsaved?: (assigned: AssignedPriceType[]) => void;
  }

  let { open, userId, userName = '', assigned, onclose, onsaved }: Props = $props();

  let types = $state<PriceType[]>([]);
  let selected = $state<number[]>([]);
  let defaultId = $state<number | null>(null);
  let loading = $state(true);
  let saving = $state(false);
  let error = $state('');

  onMount(() => {
    void loadTypes();
  });

  $effect(() => {
    if (!open) {
      return;
    }

    selected = assigned.map((type) => type.id);
    defaultId = assigned.find((type) => type.is_default)?.id ?? assigned[0]?.id ?? null;
  });

  async function loadTypes(): Promise<void> {
    loading = true;
    error = '';

    try {
      const data = await listPriceTypes();
      types = data.items;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить типы цен';
    } finally {
      loading = false;
    }
  }

  function toggle(id: number): void {
    if (selected.includes(id)) {
      selected = selected.filter((value) => value !== id);

      if (defaultId === id) {
        defaultId = selected[0] ?? null;
      }
    } else {
      selected = [...selected, id];

      if (defaultId === null) {
        defaultId = id;
      }
    }
  }

  async function save(): Promise<void> {
    saving = true;
    error = '';

    try {
      const result = await setUserPriceTypes(userId, selected, defaultId);
      onsaved?.(result.price_types);
      onclose();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить типы цен';
    } finally {
      saving = false;
    }
  }
</script>

<Modal {open} title={`Типы цен${userName ? ': ' + userName : ''}`} onclose={onclose}>
  {#if loading}
    <div class="center"><Spinner size={22} /></div>
  {:else if types.length === 0}
    <p class="hint">Типы цен не заданы. Добавьте их в «Настройках» → «Типы цен».</p>
    <div class="modal-actions">
      <Button variant="ghost" onclick={onclose}>Закрыть</Button>
    </div>
  {:else}
    <p class="hint">
      Отметьте типы цен, закреплённые за пользователем. Один из них — по умолчанию: по нему
      считаются корзина, заявки и заказы.
    </p>

    <div class="list">
      {#each types as type (type.id)}
        <div class="row" class:on={selected.includes(type.id)}>
          <label class="pick">
            <input
              type="checkbox"
              checked={selected.includes(type.id)}
              onchange={() => toggle(type.id)}
            />
            <span class="title">{type.title}</span>
          </label>
          <label class="default" class:disabled={!selected.includes(type.id)}>
            <input
              type="radio"
              name="default-price-type"
              disabled={!selected.includes(type.id)}
              checked={defaultId === type.id}
              onchange={() => (defaultId = type.id)}
            />
            по умолчанию
          </label>
        </div>
      {/each}
    </div>

    {#if error}<p class="error">{error}</p>{/if}

    <div class="modal-actions">
      <Button variant="ghost" disabled={saving} onclick={onclose}>Отмена</Button>
      <Button loading={saving} onclick={() => void save()}>Сохранить</Button>
    </div>
  {/if}
</Modal>

<style>
  .hint {
    margin: 0 0 var(--space-3);
    font-size: 13px;
    color: var(--muted);
    line-height: 1.5;
  }

  .center {
    display: flex;
    justify-content: center;
    padding: var(--space-5);
  }

  .list {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: var(--space-2) var(--space-3);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    background: var(--fill-tertiary);
  }

  .row.on {
    border-color: var(--primary-border);
    background: var(--primary-bg);
  }

  .pick {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    cursor: pointer;
  }

  .title {
    font-weight: 500;
    color: var(--text);
  }

  .default {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--muted);
    cursor: pointer;
  }

  .default.disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  .error {
    margin: var(--space-3) 0 0;
    color: var(--error-text);
    font-size: 13px;
  }

  .modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-4);
  }
</style>
