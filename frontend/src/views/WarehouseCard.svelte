<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    addWarehouseUser,
    createWarehouse,
    getWarehouse,
    listWarehouseCandidates,
    listWarehouseUsers,
    removeWarehouseUser,
    updateWarehouse,
    type WarehouseCandidate,
    type WarehouseCardData,
    type WarehouseType,
    type WarehouseUser
  } from '../lib/api/stocks';
  import { listManagers } from '../lib/api/requests';
  import type { ManagerItem } from '../lib/api/types';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';
  import Input from '../lib/components/ui/Input.svelte';
  import Modal from '../lib/components/ui/Modal.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  let { id }: { id: number } = $props();

  const isNew = $derived(id <= 0);

  const roleOptions = [
    { level: 90, title: 'Сисадмин' },
    { level: 50, title: 'Администратор' },
    { level: 10, title: 'Менеджер' }
  ];

  const levelOptions = [
    { level: 90, title: 'Сисадмин' },
    { level: 50, title: 'Администратор' },
    { level: 10, title: 'Менеджер' },
    { level: 5, title: 'Клиент' }
  ];

  const typeOptions: { value: WarehouseType; title: string }[] = [
    { value: 'main', title: 'Основной' },
    { value: 'transit', title: 'Транзитный' },
    { value: 'returns', title: 'Возвраты' },
    { value: 'reserve', title: 'Резерв' },
    { value: 'defect', title: 'Брак' }
  ];

  let warehouse = $state<WarehouseCardData | null>(null);
  let users = $state<WarehouseUser[]>([]);
  let managers = $state<ManagerItem[]>([]);
  let loading = $state(true);
  let busy = $state(false);
  let error = $state('');
  let notice = $state('');

  let tab = $state<'main' | 'contacts' | 'access'>('main');

  let name = $state('');
  let name1c = $state('');
  let address = $state('');
  let contactName = $state('');
  let phone = $state('');
  let email = $state('');
  let note = $state('');
  let type = $state<WarehouseType>('main');
  let stockNum = $state('0');
  let sort = $state('500');
  let isDefault = $state(false);
  let allowOrders = $state(true);
  let inReports = $state(true);
  let responsibleSid = $state('');
  let levels = $state<number[]>([]);
  let active = $state(true);

  let respOpen = $state(false);
  let respQuery = $state('');

  const responsibleCandidates = $derived(
    managers.filter((manager) => manager.level === 10 || manager.level === 50)
  );

  const filteredManagers = $derived(
    respQuery.trim() === ''
      ? responsibleCandidates
      : responsibleCandidates.filter((manager) =>
          `${manager.name} ${manager.login}`.toLowerCase().includes(respQuery.trim().toLowerCase())
        )
  );

  let addOpen = $state(false);
  let candidates = $state<WarehouseCandidate[]>([]);
  let candTotal = $state(0);
  let candLoading = $state(false);
  let candQuery = $state('');
  let candRoles = $state<number[]>([90, 50, 10]);
  let candSort = $state('name_asc');
  let addBusy = $state(false);

  $effect(() => {
    if (!addOpen) {
      return;
    }

    const query = candQuery;
    const roles = candRoles.join(',');
    const sort = candSort;
    const timer = setTimeout(() => void loadCandidates(query, roles, sort), 300);

    return () => clearTimeout(timer);
  });

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';
    notice = '';

    try {
      if (isNew) {
        levels = [50, 10, 5];
        sort = '500';
      } else {
        const data = await getWarehouse(id);
        applyWarehouse(data.warehouse);
        users = (await listWarehouseUsers(id)).users;
      }

      managers = (await listManagers()).items;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить склад';
    } finally {
      loading = false;
    }
  }

  function applyWarehouse(data: WarehouseCardData): void {
    warehouse = data;
    name = data.name;
    name1c = data.name_1c;
    address = data.address ?? '';
    contactName = data.contact_name ?? '';
    phone = data.phone ?? '';
    email = data.email ?? '';
    note = data.note ?? '';
    type = data.type;
    stockNum = String(data.stock_num);
    sort = String(data.sort);
    isDefault = data.is_default;
    allowOrders = data.allow_orders;
    inReports = data.in_reports;
    responsibleSid = data.responsible?.sid ?? '';
    levels = [...data.levels];
    active = data.active;
  }

  async function save(): Promise<void> {
    busy = true;
    error = '';
    notice = '';

    try {
      const payload = {
        name,
        name_1c: name1c,
        address,
        contact_name: contactName,
        phone,
        email,
        note,
        type,
        stock_num: Number(stockNum) || 0,
        sort: Number(sort) || 0,
        levels,
        active,
        is_default: isDefault,
        allow_orders: allowOrders,
        in_reports: inReports,
        responsible_sid: responsibleSid
      };

      if (isNew) {
        const created = await createWarehouse(payload);
        router.navigate(`/warehouses/${created.warehouse.id}`);
        return;
      }

      const data = await updateWarehouse(id, payload);

      applyWarehouse(data.warehouse);
      notice = 'Сохранено';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить склад';
    } finally {
      busy = false;
    }
  }

  function toggleLevel(level: number): void {
    levels = levels.includes(level) ? levels.filter((item) => item !== level) : [...levels, level];
  }

  function responsibleLabel(): string {
    const manager = managers.find((item) => item.sid === responsibleSid);

    return manager ? manager.name || manager.login : '— не назначен —';
  }

  function openResponsible(): void {
    respQuery = '';
    respOpen = true;
  }

  function pickResponsible(manager: ManagerItem): void {
    responsibleSid = manager.sid;
    respOpen = false;
  }

  function clearResponsible(): void {
    responsibleSid = '';
    respOpen = false;
  }

  function typeTitle(value: WarehouseType): string {
    return typeOptions.find((option) => option.value === value)?.title ?? value;
  }

  async function loadCandidates(query: string, roles: string, sort: string): Promise<void> {
    candLoading = true;

    try {
      const data = await listWarehouseCandidates(id, {
        q: query,
        roles: roles === '' ? [] : roles.split(',').map(Number),
        sort
      });

      candidates = data.items;
      candTotal = data.total;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить пользователей';
    } finally {
      candLoading = false;
    }
  }

  function toggleRole(level: number): void {
    candRoles = candRoles.includes(level)
      ? candRoles.filter((item) => item !== level)
      : [...candRoles, level];
  }

  function setCandSort(field: string): void {
    candSort = candSort === `${field}_asc` ? `${field}_desc` : `${field}_asc`;
  }

  function candSortTerm(field: string): 'asc' | 'desc' | null {
    if (candSort === `${field}_asc`) {
      return 'asc';
    }

    if (candSort === `${field}_desc`) {
      return 'desc';
    }

    return null;
  }

  function roleTitle(level: number): string {
    return levelOptions.find((role) => role.level === level)?.title ?? String(level);
  }

  function openAdd(): void {
    candQuery = '';
    candSort = 'name_asc';
    addOpen = true;
  }

  async function addCandidate(candidate: WarehouseCandidate): Promise<void> {
    addBusy = true;
    error = '';

    try {
      users = (await addWarehouseUser(id, candidate.sid)).users;
      candidates = candidates.map((item) =>
        item.sid === candidate.sid ? { ...item, has_access: true } : item
      );
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось добавить доступ';
    } finally {
      addBusy = false;
    }
  }

  async function removeUser(user: WarehouseUser): Promise<void> {
    if (!confirm(`Убрать доступ для «${user.full_name || user.login}»?`)) {
      return;
    }

    busy = true;
    error = '';

    try {
      users = (await removeWarehouseUser(id, user.user_sid)).users;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось убрать доступ';
    } finally {
      busy = false;
    }
  }
</script>

<section class="page">
  <div class="head">
    <a class="back" href="#/warehouses" title="К списку складов">
      <Icon name="arrow-left" size={16} />
    </a>
    <div>
      <h1>{isNew ? 'Новый склад' : (warehouse?.name ?? 'Склад')}</h1>
      <p class="sub">
        {#if isNew}
          Заполните данные склада
        {:else if warehouse}
          {warehouse.active ? 'Активен' : 'Скрыт от пользователей'}
        {:else}
          Загрузка…
        {/if}
      </p>
    </div>
  </div>

  {#if loading}
    <div class="center"><Spinner /></div>
  {:else if !isNew && !warehouse}
    <div class="alert">
      <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
      <span>{error || 'Склад не найден'}</span>
    </div>
  {:else}
    {#if error}
      <div class="alert">
        <span class="alert-icon"><Icon name="close-circle" size={16} /></span>
        <span>{error}</span>
      </div>
    {/if}
    {#if notice}
      <div class="notice">
        <span class="notice-icon"><Icon name="check-circle" size={16} /></span>
        <span>{notice}</span>
      </div>
    {/if}

    <div class="tabs">
      <button type="button" class:active={tab === 'main'} onclick={() => (tab = 'main')}>
        Основное
      </button>
      <button type="button" class:active={tab === 'contacts'} onclick={() => (tab = 'contacts')}>
        Контакты
      </button>
      {#if !isNew}
        <button type="button" class:active={tab === 'access'} onclick={() => (tab = 'access')}>
          Доступ{users.length > 0 ? ` (${users.length})` : ''}
        </button>
      {/if}
    </div>

    {#if tab === 'main'}
      <div class="card">
        <h2>Реквизиты</h2>
        <div class="grid">
          <Input label="Название" bind:value={name} />
          <Input label="Название в 1С" bind:value={name1c} placeholder="Как в выгрузке 1С" />
          <Input label="Номер склада" type="number" bind:value={stockNum} />
          <Input label="Порядок" type="number" bind:value={sort} />
        </div>

        <h2>Тип и назначение</h2>
        <div class="grid">
          <label class="field">
            <span class="field-label">Тип склада</span>
            <select bind:value={type}>
              {#each typeOptions as option (option.value)}
                <option value={option.value}>{option.title}</option>
              {/each}
            </select>
          </label>
          <div class="field">
            <span class="field-label">Ответственный</span>
            <button type="button" class="picker" onclick={openResponsible}>
              <span class="picker-value" class:picker-empty={responsibleSid === ''}>
                {responsibleLabel()}
              </span>
              <span class="picker-icon"><Icon name="arrow-down" size={14} /></span>
            </button>
          </div>
        </div>

        <div class="flags">
          <label class="checkbox">
            <input type="checkbox" bind:checked={isDefault} />
            Основной склад (по умолчанию для новых позиций)
          </label>
          {#if appSettings.requestsEnabled}
            <label class="checkbox">
              <input type="checkbox" bind:checked={allowOrders} />
              Доступен для заявок и самовывоза
            </label>
          {/if}
          {#if appSettings.reportsEnabled}
            <label class="checkbox">
              <input type="checkbox" bind:checked={inReports} />
              Учитывать в отчётах
            </label>
          {/if}
          <label class="checkbox">
            <input type="checkbox" bind:checked={active} />
            Активен (показывать позиции на этом складе)
          </label>
        </div>

        <p class="hint">
          Если склад неактивен, его позиции не показываются пользователям, но импорт из 1С на него
          продолжается и остатки обновляются.
        </p>

        <div class="actions">
          <Button loading={busy} onclick={() => void save()}>{isNew ? 'Создать' : 'Сохранить'}</Button>
        </div>
      </div>
    {:else if tab === 'contacts'}
      <div class="card">
        <h2>Размещение</h2>
        <div class="grid">
          <Input label="Адрес" bind:value={address} placeholder="Город, улица, дом" />
        </div>

        <h2>Контакты</h2>
        <div class="grid">
          <Input label="Контактное лицо" bind:value={contactName} />
          <Input label="Телефон" bind:value={phone} />
          <Input label="E-mail" bind:value={email} />
        </div>

        <h2>Комментарий</h2>
        <label class="field">
          <textarea
            bind:value={note}
            rows="3"
            aria-label="Комментарий"
            placeholder="Внутренняя заметка о складе"
          ></textarea>
        </label>

        <div class="actions">
          <Button loading={busy} onclick={() => void save()}>{isNew ? 'Создать' : 'Сохранить'}</Button>
        </div>
      </div>
    {:else}
      <div class="card">
        <h2>Доступ по ролям</h2>
        <p class="hint">
          Роли, которым склад доступен. Если снять все — склад увидят только пользователи с
          персональным доступом.
        </p>
        <div class="flags">
          {#each levelOptions as role (role.level)}
            <label class="checkbox">
              <input
                type="checkbox"
                checked={levels.includes(role.level)}
                onchange={() => toggleLevel(role.level)}
              />
              {role.title}
            </label>
          {/each}
        </div>

        <div class="actions">
          <Button loading={busy} onclick={() => void save()}>{isNew ? 'Создать' : 'Сохранить'}</Button>
        </div>

        <div class="row-head">
          <h2>Пользователи с доступом</h2>
          <Button onclick={openAdd}>Добавить</Button>
        </div>

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
                <button
                  type="button"
                  class="icon-btn"
                  title="Убрать доступ"
                  aria-label={`Убрать доступ: ${user.full_name || user.login}`}
                  onclick={() => void removeUser(user)}
                >
                  <Icon name="trash" size={16} />
                </button>
              </div>
            {/each}
          </div>
        {/if}
      </div>
    {/if}
  {/if}
</section>

<Modal open={addOpen} title="Добавить доступ к складу" wide onclose={() => (addOpen = false)}>
  <div class="cand-toolbar">
    <form
      class="cand-search"
      onsubmit={(event) => {
        event.preventDefault();
      }}
    >
      <SearchInput
        bind:value={candQuery}
        historyKey="warehouse-access"
        placeholder="Поиск по имени или логину"
      />
    </form>

    <div class="cand-roles">
      {#each roleOptions as role (role.level)}
        <label class="checkbox">
          <input
            type="checkbox"
            checked={candRoles.includes(role.level)}
            onchange={() => toggleRole(role.level)}
          />
          {role.title}
        </label>
      {/each}
    </div>
  </div>

  {#if candLoading}
    <div class="center"><Spinner /></div>
  {:else if candidates.length === 0}
    <div class="empty">Нет подходящих пользователей</div>
  {:else}
    <div class="cand-table">
      <div class="cand-head">
        <button type="button" class="cand-th" onclick={() => setCandSort('name')}>
          ФИО
          {#if candSortTerm('name')}
            <Icon name={candSortTerm('name') === 'desc' ? 'arrow-down' : 'arrow-up'} size={12} />
          {/if}
        </button>
        <button type="button" class="cand-th" onclick={() => setCandSort('login')}>
          Логин
          {#if candSortTerm('login')}
            <Icon name={candSortTerm('login') === 'desc' ? 'arrow-down' : 'arrow-up'} size={12} />
          {/if}
        </button>
        <button type="button" class="cand-th" onclick={() => setCandSort('level')}>
          Роль
          {#if candSortTerm('level')}
            <Icon name={candSortTerm('level') === 'desc' ? 'arrow-down' : 'arrow-up'} size={12} />
          {/if}
        </button>
        <button type="button" class="cand-th cand-th-access" onclick={() => setCandSort('access')}>
          Доступ
          {#if candSortTerm('access')}
            <Icon name={candSortTerm('access') === 'desc' ? 'arrow-down' : 'arrow-up'} size={12} />
          {/if}
        </button>
      </div>

      {#each candidates as candidate (candidate.id)}
        <div class="cand-row">
          <span class="cand-name">{candidate.name}</span>
          <span class="cand-login">{candidate.login}</span>
          <span class="cand-role">{roleTitle(candidate.level)}</span>
          {#if candidate.has_access}
            <span class="tag on">есть доступ</span>
          {:else}
            <button
              type="button"
              class="cand-add"
              disabled={addBusy}
              onclick={() => void addCandidate(candidate)}
            >
              Добавить
            </button>
          {/if}
        </div>
      {/each}
    </div>
  {/if}

  <div class="modal-actions">
    <span class="cand-total">Найдено: {candTotal}</span>
    <Button variant="ghost" onclick={() => (addOpen = false)}>Закрыть</Button>
  </div>
</Modal>

<Modal open={respOpen} title="Ответственный за склад" wide onclose={() => (respOpen = false)}>
  <div class="cand-toolbar">
    <form
      class="cand-search"
      onsubmit={(event) => {
        event.preventDefault();
      }}
    >
      <SearchInput
        bind:value={respQuery}
        historyKey="warehouse-responsible"
        placeholder="Поиск по имени или логину"
      />
    </form>
  </div>

  {#if filteredManagers.length === 0}
    <div class="empty">Нет подходящих пользователей</div>
  {:else}
    <div class="cand-table">
      <div class="cand-head">
        <span>ФИО</span>
        <span>Логин</span>
        <span>Роль</span>
        <span>Выбор</span>
      </div>

      {#each filteredManagers as manager (manager.id)}
        <div class="cand-row">
          <span class="cand-name">{manager.name || manager.login}</span>
          <span class="cand-login">{manager.login}</span>
          <span class="cand-role">{roleTitle(manager.level)}</span>
          {#if manager.sid === responsibleSid}
            <span class="tag on">текущий</span>
          {:else}
            <button type="button" class="cand-add" onclick={() => pickResponsible(manager)}>
              Выбрать
            </button>
          {/if}
        </div>
      {/each}
    </div>
  {/if}

  <div class="modal-actions">
    <span class="cand-total">Найдено: {filteredManagers.length}</span>
    {#if responsibleSid}
      <Button variant="ghost" onclick={clearResponsible}>Снять</Button>
    {/if}
    <Button variant="ghost" onclick={() => (respOpen = false)}>Закрыть</Button>
  </div>
</Modal>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
  }

  .head {
    display: flex;
    align-items: center;
    gap: var(--space-3);
  }

  .back {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: var(--control-height);
    height: var(--control-height);
    flex: 0 0 auto;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text);
    background: var(--surface);
  }

  .back:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  h1 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
    color: var(--text);
  }

  h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
  }

  .sub {
    margin: 2px 0 0;
    font-size: 14px;
    color: var(--text-description);
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
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--space-4);
  }

  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: var(--space-3);
  }

  .checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: var(--text);
    cursor: pointer;
  }

  .hint {
    margin: 0;
    font-size: 13px;
    color: var(--text-description);
  }

  .field {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .field-label {
    font-size: 14px;
    color: var(--text);
  }

  select,
  textarea {
    width: 100%;
    min-height: var(--control-height);
    padding: 4px 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: 14px;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }

  textarea {
    min-height: 72px;
    padding: 6px 11px;
    resize: vertical;
  }

  select:focus,
  textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--focus-ring);
  }

  .picker {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
    width: 100%;
    min-height: var(--control-height);
    padding: 4px 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: 14px;
    text-align: left;
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }

  .picker:hover {
    border-color: var(--primary);
  }

  .picker:focus-visible {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--focus-ring);
  }

  .picker-value {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .picker-empty {
    color: var(--text-description);
  }

  .picker-icon {
    display: inline-flex;
    flex: 0 0 auto;
    color: var(--text-description);
  }

  .flags {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .actions {
    display: flex;
    gap: var(--space-2);
  }

  .row-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
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

  .tag {
    justify-self: start;
    font-size: 12px;
    line-height: 20px;
    padding: 0 7px;
    border-radius: 4px;
    border: 1px solid var(--border);
    background: var(--fill-tertiary);
    color: var(--text-description);
  }

  .icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: var(--control-height);
    height: var(--control-height);
    flex: 0 0 auto;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: none;
    color: var(--text-description);
    cursor: pointer;
    transition: color 0.2s ease, background-color 0.2s ease;
  }

  .icon-btn:hover {
    color: var(--error-text);
    background: var(--fill-hover);
  }

  .cand-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--space-3);
  }

  .cand-search {
    flex: 1 1 220px;
    max-width: 320px;
    min-width: 0;
  }

  .cand-roles {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
  }

  .cand-table {
    display: flex;
    flex-direction: column;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-md);
    overflow: hidden;
  }

  .cand-head,
  .cand-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 0.7fr) 130px 110px;
    align-items: center;
    gap: var(--space-3);
    padding: 8px var(--space-3);
  }

  .cand-head {
    background: var(--fill-tertiary);
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
  }

  .cand-row {
    border-top: 1px solid var(--border-secondary);
    font-size: 14px;
  }

  .cand-row:hover {
    background: var(--fill-tertiary);
  }

  .cand-th {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
  }

  .cand-th:hover {
    color: var(--primary);
  }

  .cand-name {
    font-weight: 600;
    color: var(--text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .cand-login,
  .cand-role {
    color: var(--text-description);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .cand-row .tag {
    justify-self: stretch;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 24px;
    padding: 0 7px;
    border-radius: var(--radius-sm);
    font-size: 14px;
  }

  .cand-add {
    padding: 0 7px;
    height: 24px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--primary);
    font: inherit;
    font-size: 14px;
    cursor: pointer;
    transition: color 0.2s ease, border-color 0.2s ease;
  }

  .cand-add:hover:not(:disabled) {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .cand-add:disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }

  .cand-total {
    margin-right: auto;
    font-size: 13px;
    color: var(--text-description);
  }

  .modal-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-4);
  }

  .alert,
  .notice {
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    color: var(--text);
    font-size: 14px;
  }

  .alert {
    border-color: var(--error-border);
    background: var(--danger-bg);
  }

  .notice {
    border-color: var(--success-border);
    background: var(--success-bg);
  }

  .alert-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
    color: var(--danger);
  }

  .notice-icon {
    display: inline-flex;
    flex: 0 0 auto;
    margin-top: 2px;
    color: var(--success);
  }

  .empty {
    padding: var(--space-6);
    text-align: center;
    color: var(--text-description);
    background: var(--surface);
    border: 1px dashed var(--border-secondary);
    border-radius: var(--radius-md);
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-6);
  }
</style>
