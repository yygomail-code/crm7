<script lang="ts">
  import { listLevels, listWarehouses } from '../../api/stocks';
  import { listItemGroups } from '../../api/groups';
  import type { CreateRequestItem } from '../../api/requests';
  import type { ItemGroup, StockLevel } from '../../api/types';
  import { appSettings } from '../../stores/app-settings.svelte';
  import { formatPrice, stockQuantityText } from '../../format';
  import Button from '../ui/Button.svelte';
  import Modal from '../ui/Modal.svelte';
  import Spinner from '../ui/Spinner.svelte';

  interface Props {
    open: boolean;
    items: CreateRequestItem[];
    onclose: () => void;
    onchange?: (items: CreateRequestItem[]) => void;
    clientId?: number;
  }

  let { open, items, onclose, onchange, clientId = 0 }: Props = $props();

  let warehouses = $state<{ id: number; name: string; positions: number }[]>([]);
  let activeId = $state(0);
  let searchInput = $state('');
  let query = $state('');
  let levels = $state<StockLevel[]>([]);
  let total = $state(0);
  let page = $state(1);
  let loading = $state(false);
  let loadingMore = $state(false);
  let draft = $state<CreateRequestItem[]>([]);
  let initialized = $state(false);
  let tabsEl = $state<HTMLElement | null>(null);
  let tabsOverflow = $state(false);
  let tabsAtStart = $state(true);
  let tabsAtEnd = $state(false);
  let loadedClientId = -1;
  let groups = $state<ItemGroup[]>([]);
  let groupFilter = $state(0);

  const pricesEnabled = $derived(appSettings.pricesEnabled);
  const groupsEnabled = $derived(appSettings.groupsEnabled);

  $effect(() => {
    if (groupsEnabled && groups.length === 0) {
      void loadGroups();
    }
  });

  async function loadGroups(): Promise<void> {
    try {
      const data = await listItemGroups();
      groups = data.items;
    } catch {
      groups = [];
    }
  }

  $effect(() => {
    if (!open) {
      initialized = false;

      return;
    }

    if (!initialized) {
      initialized = true;
      void init();
    }
  });

  $effect(() => {
    void clientId;

    if (open && initialized && loadedClientId !== clientId) {
      loadedClientId = clientId;
      void load(1, false);
    }
  });

  async function init(): Promise<void> {
    loadedClientId = clientId;
    draft = items.map((item) => ({ ...item }));
    searchInput = '';
    query = '';
    groupFilter = 0;

    if (warehouses.length === 0) {
      try {
        warehouses = (await listWarehouses()).items.map((warehouse) => ({
          id: warehouse.id,
          name: warehouse.name,
          positions: warehouse.positions
        }));
      } catch {
        warehouses = [];
      }
    }

    if (activeId === 0) {
      activeId = warehouses[0]?.id ?? 0;
    }

    await load(1, false);
  }

  async function load(targetPage: number, append: boolean): Promise<void> {
    if (activeId === 0) {
      levels = [];
      total = 0;

      return;
    }

    if (append) {
      loadingMore = true;
    } else {
      loading = true;
    }

    try {
      const result = await listLevels(
        activeId,
        {
          q: query,
          show_zero: appSettings.allowZeroStock,
          group_id: groupFilter > 0 ? groupFilter : undefined
        },
        targetPage,
        50,
        clientId
      );

      levels = append ? [...levels, ...result.items] : result.items;
      total = result.total;
      page = targetPage;
    } catch {
      if (!append) {
        levels = [];
        total = 0;
      }
    } finally {
      loading = false;
      loadingMore = false;
    }
  }

  async function select(id: number): Promise<void> {
    if (id === activeId) {
      return;
    }

    activeId = id;
    query = searchInput.trim();

    await load(1, false);
  }

  async function searchSubmit(): Promise<void> {
    query = searchInput.trim();

    await load(1, false);
  }

  async function changeGroup(): Promise<void> {
    await load(1, false);
  }

  async function loadMore(): Promise<void> {
    await load(page + 1, true);
  }

  function updateTabsScroll(): void {
    const el = tabsEl;

    if (!el) {
      return;
    }

    tabsOverflow = el.scrollWidth - el.clientWidth > 4;
    tabsAtStart = el.scrollLeft <= 4;
    tabsAtEnd = el.scrollLeft + el.clientWidth >= el.scrollWidth - 4;
  }

  function scrollTabs(direction: number): void {
    const el = tabsEl;

    if (!el) {
      return;
    }

    const step = Math.max(200, Math.round(el.clientWidth * 0.6));
    el.scrollBy({ left: direction * step, behavior: 'smooth' });
  }

  function scrollActiveIntoView(): void {
    const el = tabsEl;
    const active = el?.querySelector<HTMLElement>('.tab.active');

    if (!el || !active) {
      return;
    }

    const left = active.offsetLeft;
    const right = left + active.offsetWidth;

    if (left < el.scrollLeft + 4) {
      el.scrollLeft = Math.max(0, left - 8);
    } else if (right > el.scrollLeft + el.clientWidth - 4) {
      el.scrollLeft = right - el.clientWidth + 8;
    }

    updateTabsScroll();
  }

  $effect(() => {
    void warehouses.length;
    void activeId;

    const el = tabsEl;

    if (!el) {
      return;
    }

    const update = (): void => {
      updateTabsScroll();
      scrollActiveIntoView();
    };
    const observer = new ResizeObserver(update);
    observer.observe(el);
    window.addEventListener('resize', update);
    const frame = requestAnimationFrame(update);
    const timer = setTimeout(update, 80);

    return () => {
      observer.disconnect();
      window.removeEventListener('resize', update);
      cancelAnimationFrame(frame);
      clearTimeout(timer);
    };
  });

  function key(warehouseId: number | null, name: string): string {
    return `${warehouseId ?? 0}|${name.trim().toLowerCase()}`;
  }

  function pickedCount(warehouseId: number): number {
    return draft.filter((item) => item.warehouse_id === warehouseId).length;
  }

  function quantity(level: StockLevel): number {
    const levelKey = key(activeId, level.name);
    const found = draft.find((item) => key(item.warehouse_id, item.name) === levelKey);

    return found ? Number(found.quantity) : 0;
  }

  function change(level: StockLevel, delta: number): void {
    const levelKey = key(activeId, level.name);
    const index = draft.findIndex((item) => key(item.warehouse_id, item.name) === levelKey);

    if (index === -1) {
      if (delta <= 0) {
        return;
      }

      const warehouse = warehouses.find((item) => item.id === activeId);

      draft = [
        ...draft,
        {
          name: level.name,
          unit: level.unit,
          description: level.description,
          quantity: delta,
          warehouse_id: activeId,
          warehouse_name: warehouse?.name ?? '',
          stock_level_id: level.id
        }
      ];
    } else {
      const next = Number(draft[index].quantity) + delta;

      if (next <= 0) {
        draft = draft.filter((_, position) => position !== index);
      } else {
        draft = draft.map((item, position) =>
          position === index ? { ...item, quantity: next } : item
        );
      }
    }

    onchange?.(draft.map((item) => ({ ...item })));
  }
</script>

<Modal {open} title="Складские остатки" wide {onclose}>
  {#if warehouses.length === 0}
    <p class="muted">Нет доступных складов</p>
  {:else}
    <div class="tabs-row">
      {#if tabsOverflow}
        <button
          type="button"
          class="tabs-nav"
          title="Прокрутить влево"
          aria-label="Прокрутить список складов влево"
          disabled={tabsAtStart}
          onclick={() => scrollTabs(-1)}
        >
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M15 6l-6 6 6 6" />
          </svg>
        </button>
      {/if}

      <div class="tabs-wrap" class:overflow={tabsOverflow && !tabsAtEnd}>
        <div class="picker-tabs" bind:this={tabsEl} onscroll={updateTabsScroll}>
          {#each warehouses as warehouse (warehouse.id)}
            <button
              type="button"
              class="tab"
              class:active={warehouse.id === activeId}
              onclick={() => void select(warehouse.id)}
            >
              {warehouse.name}
              <span class="tab-count">{warehouse.positions}</span>
              <span class="tab-picked">— {pickedCount(warehouse.id)}</span>
            </button>
          {/each}
        </div>
      </div>

      {#if tabsOverflow}
        <button
          type="button"
          class="tabs-nav"
          title="Прокрутить вправо"
          aria-label="Прокрутить список складов вправо"
          disabled={tabsAtEnd}
          onclick={() => scrollTabs(1)}
        >
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M9 6l6 6-6 6" />
          </svg>
        </button>
      {/if}
    </div>

    <p class="muted picker-hint">Отмечены позиции, уже добавленные в заявку ({draft.length})</p>

    <form
      class="picker-search"
      onsubmit={(event) => {
        event.preventDefault();
        void searchSubmit();
      }}
    >
      <input class="text-input" bind:value={searchInput} placeholder="Поиск товара" />
      {#if groupsEnabled && groups.length > 0}
        <select
          class="group-select"
          bind:value={groupFilter}
          aria-label="Группа позиций"
          onchange={() => void changeGroup()}
        >
          <option value={0}>Все группы</option>
          {#each groups as group (group.id)}
            <option value={group.id}>{group.title}</option>
          {/each}
        </select>
      {/if}
      <Button type="submit" variant="ghost">Найти</Button>
    </form>

    {#if loading}
      <div class="center"><Spinner size={22} /></div>
    {:else if levels.length === 0}
      <p class="muted">Ничего не найдено</p>
    {:else}
      <div class="picker-list">
        {#each levels as level (level.id)}
          {@const picked = quantity(level)}
          <div class="picker-row" class:picked={picked > 0}>
            <button
              type="button"
              class="pr-name"
              class:addable={picked === 0}
              aria-label={picked === 0 ? `Добавить в заявку: ${level.name}` : undefined}
              onclick={() => {
                if (picked === 0) {
                  change(level, 1);
                }
              }}
            >
              {level.name}
            </button>
            <span class="pr-stock">
              на складе: {stockQuantityText(level.quantity, level.unit)}
            </span>
            {#if groupsEnabled && level.group_title}
              <span class="pr-group">{level.group_title}</span>
            {/if}
            {#if pricesEnabled}
              <span class="pr-price" class:missing={level.price === null}>
                {formatPrice(level.price)}
              </span>
            {/if}
            {#if picked > 0}
              <span class="pr-controls">
                <button
                  type="button"
                  class="step"
                  aria-label={`Уменьшить: ${level.name}`}
                  onclick={() => change(level, -1)}
                >
                  −
                </button>
                <span class="pr-qty">
                  {picked.toLocaleString('ru-RU', { maximumFractionDigits: 3 })}
                </span>
                <button
                  type="button"
                  class="step"
                  aria-label={`Увеличить: ${level.name}`}
                  onclick={() => change(level, 1)}
                >
                  +
                </button>
              </span>
            {:else}
              <button
                type="button"
                class="step"
                aria-label={`Добавить в заявку: ${level.name}`}
                onclick={() => change(level, 1)}
              >
                +
              </button>
            {/if}
          </div>
        {/each}
      </div>
    {/if}
  {/if}

  {#if !loading && levels.length > 0 && levels.length < total}
    <div class="modal-actions">
      <Button variant="ghost" loading={loadingMore} onclick={() => void loadMore()}>
        Показать ещё ({levels.length} из {total})
      </Button>
    </div>
  {/if}
</Modal>

<style>
  .muted {
    color: var(--muted);
    font-size: 13px;
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-4) 0;
  }

  .text-input {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    outline: none;
  }

  .text-input:focus {
    border-color: var(--primary);
  }

  .step {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface);
    color: var(--text);
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
  }

  .step:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .picker-hint {
    margin: -4px 0 var(--space-3);
    font-size: 12px;
  }

  .tabs-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    margin-bottom: var(--space-3);
  }

  .tabs-nav {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 30px;
    height: 30px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
    transition: border-color 0.12s ease, color 0.12s ease;
  }

  .tabs-nav:hover:not(:disabled) {
    border-color: var(--primary);
    color: var(--primary);
  }

  .tabs-nav:disabled {
    opacity: 0.4;
    cursor: default;
  }

  .tabs-wrap {
    position: relative;
    flex: 1 1 auto;
    min-width: 0;
  }

  .tabs-wrap.overflow::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: 40px;
    pointer-events: none;
    background: linear-gradient(to right, transparent, var(--surface) 85%);
  }

  .picker-tabs {
    display: flex;
    flex-wrap: nowrap;
    gap: var(--space-2);
    overflow-x: auto;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 2px;
  }

  .picker-tabs::-webkit-scrollbar {
    display: none;
  }

  .picker-tabs .tab {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--muted);
    font: inherit;
    font-size: 13px;
    white-space: nowrap;
    cursor: pointer;
  }

  .picker-tabs .tab.active {
    border-color: var(--primary);
    color: var(--primary);
    background: color-mix(in srgb, var(--primary) 8%, white);
  }

  .picker-tabs .tab-count {
    font-size: 11px;
    color: var(--muted);
  }

  .picker-tabs .tab-picked {
    font-size: 11px;
    font-weight: 600;
    color: var(--primary);
  }

  .picker-search {
    display: flex;
    gap: var(--space-2);
    margin-bottom: var(--space-3);
  }

  .picker-search .text-input {
    flex: 1;
    min-width: 0;
  }

  .picker-list {
    display: flex;
    flex-direction: column;
    margin-bottom: var(--space-3);
  }

  .picker-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: 6px 8px;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
  }

  .picker-row.picked {
    background: color-mix(in srgb, var(--primary) 7%, white);
  }

  .pr-name {
    flex: 1;
    min-width: 0;
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    cursor: default;
  }

  .pr-name.addable {
    cursor: pointer;
  }

  .pr-name.addable:hover {
    color: var(--primary);
    text-decoration: underline;
  }

  .pr-stock {
    color: var(--muted);
    font-size: 12px;
    white-space: nowrap;
  }

  .pr-group {
    color: var(--muted);
    font-size: 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 160px;
  }

  .group-select {
    flex: 0 0 auto;
    max-width: 190px;
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: 13px;
    outline: none;
  }

  .group-select:focus {
    border-color: var(--primary);
  }

  .pr-price {
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
  }

  .pr-price.missing {
    color: var(--muted);
    font-weight: 400;
  }

  .pr-controls {
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }

  .pr-qty {
    min-width: 44px;
    text-align: center;
    font-weight: 500;
  }

  .modal-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2);
    margin-top: var(--space-3);
  }
</style>
