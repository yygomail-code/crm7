<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    getWarehouse,
    listWarehouseUsers,
    type WarehouseCardData,
    type WarehouseType,
    type WarehouseUser
  } from '../lib/api/stocks';
  import { listPriceTypes } from '../lib/api/prices';
  import type { PriceType } from '../lib/api/types';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  let { id }: { id: number } = $props();

  const typeTitles: Record<WarehouseType, string> = {
    main: 'Основной',
    transit: 'Транзитный',
    returns: 'Возвраты',
    reserve: 'Резерв',
    defect: 'Брак'
  };

  const roleTitles: Record<number, string> = {
    90: 'Сисадмин',
    50: 'Администратор',
    10: 'Менеджер',
    5: 'Клиент'
  };

  let warehouse = $state<WarehouseCardData | null>(null);
  let users = $state<WarehouseUser[]>([]);
  let priceTypes = $state<PriceType[]>([]);
  let loading = $state(true);
  let error = $state('');

  let tab = $state<'main' | 'contacts' | 'prices' | 'access'>('main');

  const selectedPriceTypes = $derived(
    priceTypes.filter((type) => (warehouse?.price_type_ids ?? []).includes(type.id))
  );

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      const data = await getWarehouse(id);
      warehouse = data.warehouse;
      users = (await listWarehouseUsers(id)).users;

      if (appSettings.pricesEnabled) {
        priceTypes = (await listPriceTypes().catch(() => ({ items: [] }))).items;
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить склад';
    } finally {
      loading = false;
    }
  }

  function typeTitle(value: WarehouseType): string {
    return typeTitles[value] ?? value;
  }

  function roleTitle(level: number): string {
    return roleTitles[level] ?? String(level);
  }
</script>

<section class="page">
  <div class="head">
    <button
      type="button"
      class="back"
      title="Назад к складам"
      aria-label="Назад к складам"
      onclick={() => router.navigate('/warehouses')}
    >
      <Icon name="arrow-left" size={16} />
    </button>
    <div class="head-text">
      <h1>{warehouse?.name ?? 'Склад'}</h1>
      {#if warehouse}
        <p class="sub">
          {typeTitle(warehouse.type)} · {warehouse.active ? 'Активен' : 'Скрыт от пользователей'}
        </p>
      {/if}
    </div>
    {#if warehouse}
      <div class="head-actions">
        <Button variant="ghost" onclick={() => router.navigate(`/warehouses/${id}/edit`)}>
          <Icon name="edit" size={16} /> Редактировать
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

  {#if loading}
    <div class="center"><Spinner size={26} /></div>
  {:else if warehouse}
    <div class="tabs">
      <button type="button" class:active={tab === 'main'} onclick={() => (tab = 'main')}>
        Основное
      </button>
      <button type="button" class:active={tab === 'contacts'} onclick={() => (tab = 'contacts')}>
        Контакты
      </button>
      {#if appSettings.pricesEnabled}
        <button type="button" class:active={tab === 'prices'} onclick={() => (tab = 'prices')}>
          Цены
        </button>
      {/if}
      <button type="button" class:active={tab === 'access'} onclick={() => (tab = 'access')}>
        Доступ{users.length > 0 ? ` (${users.length})` : ''}
      </button>
    </div>

    <div class="card">
      {#if tab === 'main'}
        <h2>Реквизиты</h2>
        <div class="grid">
          <div class="field">
            <span class="label">Название</span>
            <span class="value">{warehouse.name}</span>
          </div>
          <div class="field">
            <span class="label">Название в 1С</span>
            <span class="value">{warehouse.name_1c || '—'}</span>
          </div>
          <div class="field">
            <span class="label">Номер склада</span>
            <span class="value">{warehouse.stock_num}</span>
          </div>
          <div class="field">
            <span class="label">Порядок</span>
            <span class="value">{warehouse.sort}</span>
          </div>
        </div>

        <h2>Тип и назначение</h2>
        <div class="grid">
          <div class="field">
            <span class="label">Тип склада</span>
            <span class="value">{typeTitle(warehouse.type)}</span>
          </div>
          <div class="field">
            <span class="label">Ответственный (сотрудник)</span>
            <span class="value">{warehouse.responsible?.name || '— не назначен —'}</span>
          </div>
        </div>

        <h2>Флаги</h2>
        <div class="flags">
          {#if appSettings.requestsEnabled}
            <div class="flag">
              <span class="flag-label">Доступен для заявок и самовывоза</span>
              <span class="flag-value" class:on={warehouse.allow_orders}>
                {warehouse.allow_orders ? 'Да' : 'Нет'}
              </span>
            </div>
          {/if}
          {#if appSettings.reportsEnabled}
            <div class="flag">
              <span class="flag-label">Учитывать в отчётах</span>
              <span class="flag-value" class:on={warehouse.in_reports}>
                {warehouse.in_reports ? 'Да' : 'Нет'}
              </span>
            </div>
          {/if}
          <div class="flag">
            <span class="flag-label">Активен</span>
            <span class="flag-value" class:on={warehouse.active}>
              {warehouse.active ? 'Да' : 'Нет'}
            </span>
          </div>
        </div>

        {#if !warehouse.active}
          <p class="hint">
            Склад скрыт: его позиции не показываются пользователям, но импорт из 1С продолжается и
            остатки обновляются.
          </p>
        {/if}
      {:else if tab === 'contacts'}
        <h2>Размещение</h2>
        <div class="grid">
          <div class="field">
            <span class="label">Адрес</span>
            <span class="value">{warehouse.address || '—'}</span>
          </div>
        </div>

        <h2>Контакты</h2>
        <div class="grid">
          <div class="field">
            <span class="label">Контактное лицо (для связи)</span>
            <span class="value">{warehouse.contact_name || '—'}</span>
          </div>
          <div class="field">
            <span class="label">Телефон</span>
            <span class="value">{warehouse.phone || '—'}</span>
          </div>
          <div class="field">
            <span class="label">E-mail</span>
            <span class="value">{warehouse.email || '—'}</span>
          </div>
        </div>

        <h2>Комментарий</h2>
        <div class="field">
          <span class="value desc">{warehouse.note || '—'}</span>
        </div>
      {:else if tab === 'prices'}
        <h2>Типы цен склада</h2>
        {#if selectedPriceTypes.length === 0}
          <p class="hint">Типы цен не заданы.</p>
        {:else}
          <div class="tags">
            {#each selectedPriceTypes as priceType (priceType.id)}
              <span class="tag">{priceType.title}</span>
            {/each}
          </div>
        {/if}
      {:else}
        <div class="access-grid">
          <section class="access-block">
            <h2>Доступ по ролям</h2>
            {#if warehouse.levels.length === 0}
              <p class="hint">Роли не заданы.</p>
            {:else}
              <div class="tags">
                {#each warehouse.levels as level (level)}
                  <span class="tag">{roleTitle(level)}</span>
                {/each}
              </div>
            {/if}
          </section>

          <section class="access-block">
            <h2>Пользователи с доступом</h2>
            {#if users.length === 0}
              <div class="empty">Персональный доступ никому не выдан</div>
            {:else}
              <div class="users">
                {#each users as user (user.id)}
                  <div class="user">
                    <div class="who">
                      <strong>{user.full_name || user.login}</strong>
                      <span class="login">{user.login}</span>
                      {#if !user.active}<span class="tag">неактивен</span>{/if}
                    </div>
                  </div>
                {/each}
              </div>
            {/if}
          </section>
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
    width: var(--control-height);
    height: var(--control-height);
    flex: 0 0 auto;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
  }

  .back:hover {
    border-color: var(--primary);
    color: var(--primary);
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

  .tabs {
    display: flex;
    gap: var(--space-6);
    border-bottom: 1px solid var(--border-secondary);
  }

  .tabs button {
    padding: 12px 0;
    border: none;
    border-bottom: 2px solid transparent;
    background: none;
    color: var(--text);
    font-size: 14px;
    cursor: pointer;
    transition: color 0.2s ease;
  }

  .tabs button:hover {
    color: var(--primary);
  }

  .tabs button.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
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

  .access-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: var(--space-4);
  }

  .access-block {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    min-width: 0;
    padding: var(--space-3) var(--space-4);
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    background: var(--fill-tertiary);
  }

  .access-block h2 {
    font-size: 15px;
  }

  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: var(--space-3);
  }

  .field {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    min-width: 0;
  }

  .label {
    font-size: 14px;
    color: var(--text-description);
  }

  .value {
    font-size: 14px;
    color: var(--text);
    overflow-wrap: anywhere;
  }

  .value.desc {
    white-space: pre-wrap;
    line-height: 1.5;
  }

  .flags {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: var(--space-2);
  }

  .flag {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    padding: 8px 12px;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
  }

  .flag-label {
    font-size: 14px;
    color: var(--text-description);
  }

  .flag-value {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-description);
  }

  .flag-value.on {
    color: var(--success-text);
  }

  .tags {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .tag {
    padding: 0 7px;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: var(--fill-tertiary);
    font-size: 12px;
    line-height: 20px;
    color: var(--text-description);
  }

  .users {
    display: flex;
    flex-direction: column;
  }

  .user {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: 10px 0;
  }

  .user + .user {
    border-top: 1px solid var(--border-secondary);
  }

  .who {
    display: flex;
    align-items: baseline;
    gap: var(--space-2);
    min-width: 0;
  }

  .login {
    font-size: 13px;
    color: var(--text-description);
  }

  .hint {
    margin: 0;
    font-size: 13px;
    color: var(--text-description);
  }

  .empty {
    padding: var(--space-5);
    text-align: center;
    font-size: 14px;
    color: var(--text-description);
    border: 1px dashed var(--border-secondary);
    border-radius: var(--radius-md);
  }

  .alert {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 8px 12px;
    border: 1px solid var(--error-border);
    border-radius: var(--radius-md);
    background: var(--danger-bg);
    color: var(--text);
    font-size: 14px;
  }

  .alert-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
    color: var(--danger);
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }
</style>
