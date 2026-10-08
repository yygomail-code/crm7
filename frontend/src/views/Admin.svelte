<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    blockUser,
    createUser,
    listAudit,
    listItemTypes,
    listModules,
    listRoles,
    listUsers,
    resetUserPassword,
    saveItemTypes,
    saveModules,
    saveRole,
    searchLog,
    type ModuleItem,
    type ModuleRole,
    unblockUser,
    updateUser
  } from '../lib/api/admin';
  import type { ItemType } from '../lib/api/types';
  import { listLegalDocuments, saveLegalDocument, type LegalDocument } from '../lib/api/legal';
  import { listPriceTypes } from '../lib/api/prices';
  import {
    createItemGroup,
    deleteItemGroup,
    listAdminItemGroups,
    updateItemGroup
  } from '../lib/api/groups';
  import type {
    AdminUser,
    AuditEntry,
    ItemGroup,
    PriceType,
    RoleCapability,
    RoleInfo,
    SearchLogEntry,
    SearchQueryStat
  } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { router } from '../lib/router.svelte';
  import { addHistory } from '../lib/search-history';
  import { clearFilters, countActive, loadFilters, saveFilters } from '../lib/filters';
  import { clampDates, todayIso } from '../lib/period';
  import Button from '../lib/components/ui/Button.svelte';
  import FiltersModal from '../lib/components/ui/FiltersModal.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { formatDateTime } from '../lib/format';
  import { auditLabel, entityLabel } from '../lib/labels';

  const levelTitles = $derived<Record<number, string>>({
    90: 'Сисадмин',
    50: 'Администратор',
    10: 'Менеджер',
    5: appSettings.clientLabel,
    1: 'Гость'
  });

  const adminCoreCapabilities = ['users.manage', 'roles.manage', 'settings.manage'];

  const userFilterDefaults = { level: 0, state: '' };
  const auditFilterDefaults = { from: '', to: '' };

  let tab = $state<
    'users' | 'modules' | 'types' | 'audit' | 'searches' | 'roles' | 'refs' | 'docs' | 'groups'
  >('users');

  let users = $state<AdminUser[]>([]);
  let usersTotal = $state(0);
  let usersPage = $state(1);
  let userQuery = $state('');
  const initialUserFilters = loadFilters('admin-users', userFilterDefaults);
  let userFilters = $state({ ...initialUserFilters });
  let userDraft = $state({ ...initialUserFilters });
  let usersLoading = $state(true);

  let editing = $state<AdminUser | null>(null);
  let showCreate = $state(false);
  let priceTypes = $state<PriceType[]>([]);
  let itemGroups = $state<ItemGroup[]>([]);
  let groupDrafts = $state<Record<number, string>>({});
  let newGroupTitle = $state('');
  let groupsBusy = $state(false);
  let form = $state({
    name: '',
    email: '',
    phone: '',
    company: '',
    inn: '',
    position: '',
    level: 5,
    password: '',
    price_type_id: 0
  });
  let editForm = $state({ name: '', phone: '', company: '', inn: '', position: '', level: 5, price_type_id: 0 });

  let audit = $state<AuditEntry[]>([]);
  let auditTotal = $state(0);
  let auditPage = $state(1);
  let auditAction = $state('');
  const initialAuditFilters = loadFilters('admin-audit', auditFilterDefaults);
  let auditFilters = $state({ ...initialAuditFilters });
  let auditDraft = $state({ ...initialAuditFilters });
  let auditLoading = $state(false);

  let searchStats = $state({ total: 0, users: 0, unique_queries: 0 });
  let searchTop = $state<SearchQueryStat[]>([]);
  let searchRecent = $state<SearchLogEntry[]>([]);
  let searchLoading = $state(false);

  let roles = $state<RoleInfo[]>([]);
  let roleCatalog = $state<RoleCapability[]>([]);
  let roleDraft = $state<Record<number, string[]>>({});
  let roleBusy = $state(0);
  let roleNotice = $state('');
  let roleError = $state('');
  let statuses = $state<{ code: string; title: string; sort: number; color: string; is_final: boolean }[]>([]);
  let activityTypes = $state<{ code: string; title: string; audience: string; sort: number }[]>([]);
  let docs = $state<LegalDocument[]>([]);  let docCode = $state('');
  let docTitle = $state('');
  let docBody = $state('');
  let docBusy = $state(false);
  let docNotice = $state('');

  let error = $state('');
  let message = $state('');
  let busy = $state(false);

  let modules = $state<ModuleItem[]>([]);
  let moduleRoles = $state<ModuleRole[]>([]);
  let modulesLoading = $state(false);
  let modulesBusy = $state(false);
  let modulesNotice = $state('');
  let modulesError = $state('');

  let itemTypes = $state<ItemType[]>([]);
  let typesLoading = $state(false);
  let typesBusy = $state(false);
  let typesNotice = $state('');
  let typesError = $state('');

  const canManageUsers = $derived(auth.can('users.manage'));
  const canViewAudit = $derived(auth.can('audit.view'));
  const canManageRoles = $derived(auth.can('roles.manage'));

  onMount(() => {
    if (canManageUsers) {
      void loadUsers();
      void loadPriceTypes();
    } else if (canViewAudit) {
      tab = 'audit';
      void loadAudit();
    } else if (auth.can('settings.manage')) {
      tab = 'modules';
      void loadModules();
    }
  });

  async function loadPriceTypes(): Promise<void> {
    try {
      priceTypes = (await listPriceTypes()).items;
    } catch {
      priceTypes = [];
    }
  }

  async function loadItemGroups(): Promise<void> {
    try {
      const data = await listAdminItemGroups();
      itemGroups = data.items;
      groupDrafts = Object.fromEntries(data.items.map((group) => [group.id, group.title]));
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить группы';
    }
  }

  async function addGroup(): Promise<void> {
    const title = newGroupTitle.trim();

    if (title === '') {
      return;
    }

    groupsBusy = true;
    error = '';
    message = '';

    try {
      await createItemGroup(title);
      newGroupTitle = '';
      await loadItemGroups();
      message = 'Группа добавлена';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось добавить группу';
    } finally {
      groupsBusy = false;
    }
  }

  async function saveGroup(group: ItemGroup): Promise<void> {
    const title = (groupDrafts[group.id] ?? '').trim();

    if (title === '') {
      error = 'Укажите название группы';
      return;
    }

    groupsBusy = true;
    error = '';
    message = '';

    try {
      await updateItemGroup(group.id, title);
      await loadItemGroups();
      message = 'Группа сохранена';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить группу';
    } finally {
      groupsBusy = false;
    }
  }

  async function removeGroup(group: ItemGroup): Promise<void> {
    if (!confirm(`Удалить группу «${group.title}»? Позиции останутся без группы.`)) {
      return;
    }

    groupsBusy = true;
    error = '';
    message = '';

    try {
      await deleteItemGroup(group.id);
      await loadItemGroups();
      message = 'Группа удалена';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось удалить группу';
    } finally {
      groupsBusy = false;
    }
  }

  async function loadUsers(): Promise<void> {
    usersLoading = true;
    error = '';

    try {
      const data = await listUsers({
        q: userQuery,
        level: userFilters.level,
        state: userFilters.state,
        page: usersPage,
        per_page: 50
      });

      users = data.items;
      usersTotal = data.total;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить пользователей';
    } finally {
      usersLoading = false;
    }
  }

  function submitUsersSearch(): void {
    usersPage = 1;
    addHistory('admin-users', userQuery);
    void loadUsers();
  }

  function applyUserFilterDraft(): void {
    userFilters = { ...userDraft };
    saveFilters('admin-users', userFilters);
    usersPage = 1;
    void loadUsers();
  }

  function resetUserFilters(): void {
    userDraft = { ...userFilterDefaults };
    userFilters = { ...userFilterDefaults };
    clearFilters('admin-users');
    usersPage = 1;
    void loadUsers();
  }

  function clampAuditDates(): void {
    const dates = clampDates(auditDraft.from, auditDraft.to);

    auditDraft.from = dates.from;
    auditDraft.to = dates.to;
  }

  function applyAuditDraft(): void {
    clampAuditDates();
    auditFilters = { ...auditDraft };
    saveFilters('admin-audit', auditFilters);
    auditPage = 1;
    void loadAudit();
  }

  function applyAuditSearch(): void {
    auditPage = 1;
    addHistory('admin-audit', auditAction);
    void loadAudit();
  }

  function resetAuditFilters(): void {
    auditDraft = { ...auditFilterDefaults };
    auditFilters = { ...auditFilterDefaults };
    clearFilters('admin-audit');
    auditPage = 1;
    void loadAudit();
  }

  async function submitCreate(): Promise<void> {
    busy = true;
    error = '';
    message = '';

    try {
      const result = await createUser({ ...form });

      message = result.password
        ? `Создан ${result.login}. Пароль: ${result.password} (показывается один раз)`
        : `Создан ${result.login}`;

      form = { name: '', email: '', phone: '', company: '', inn: '', position: '', level: 5, password: '', price_type_id: 0 };
      showCreate = false;
      await loadUsers();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось создать пользователя';
    } finally {
      busy = false;
    }
  }

  function startEdit(user: AdminUser): void {
    editing = user;
    editForm = {
      name: user.name,
      phone: user.phone,
      company: user.company,
      inn: user.inn,
      position: user.position,
      level: user.level,
      price_type_id: user.price_type_id ?? 0
    };
  }

  async function submitEdit(): Promise<void> {
    if (editing === null) {
      return;
    }

    busy = true;
    error = '';
    message = '';

    try {
      await updateUser(editing.id, { ...editForm });
      message = 'Данные сохранены';
      editing = null;
      await loadUsers();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить';
    } finally {
      busy = false;
    }
  }

  async function toggleBlock(user: AdminUser): Promise<void> {
    busy = true;
    error = '';
    message = '';

    try {
      if (user.active) {
        await blockUser(user.id);
        message = `${user.name}: доступ заблокирован`;
      } else {
        await unblockUser(user.id);
        message = `${user.name}: доступ восстановлен`;
      }

      await loadUsers();
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось изменить доступ';
    } finally {
      busy = false;
    }
  }

  async function resetPassword(user: AdminUser): Promise<void> {
    if (!confirm(`Сбросить пароль пользователю ${user.name}?`)) {
      return;
    }

    busy = true;
    error = '';
    message = '';

    try {
      const result = await resetUserPassword(user.id);
      message = `${user.name}: новый пароль ${result.password} (показывается один раз)`;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сбросить пароль';
    } finally {
      busy = false;
    }
  }

  async function loadAudit(): Promise<void> {
    auditLoading = true;
    error = '';

    try {
      const data = await listAudit({
        action: auditAction,
      from: auditFilters.from,
      to: auditFilters.to,
        page: auditPage,
        per_page: 50
      });

      audit = data.items;
      auditTotal = data.total;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить аудит';
    } finally {
      auditLoading = false;
    }
  }

  function selectDoc(document: LegalDocument): void {
    docCode = document.code;
    docTitle = document.title;
    docBody = document.body;
    docNotice = '';
  }

  const docPlaceholders = $derived([...new Set(docBody.match(/\[[^\]]+\]/g) ?? [])]);

  async function saveDoc(): Promise<void> {
    docBusy = true;
    docNotice = '';
    error = '';

    try {
      const result = await saveLegalDocument(docCode, docTitle, docBody);
      docs = result.items;
      docNotice = 'Документ сохранён';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сохранить документ';
    } finally {
      docBusy = false;
    }
  }

  async function openTab(
    next: 'users' | 'modules' | 'types' | 'audit' | 'searches' | 'roles' | 'refs' | 'docs' | 'groups'
  ): Promise<void> {
    tab = next;

    if (next === 'modules' && modules.length === 0) {
      await loadModules();
    }

    if (next === 'types' && itemTypes.length === 0) {
      await loadItemTypes();
    }

    if (next === 'docs' && docs.length === 0) {
      try {
        docs = (await listLegalDocuments()).items;

        if (docs.length > 0) {
          selectDoc(docs[0]);
        }
      } catch {
        // некритично
      }
    }

    if (next === 'groups') {
      await loadItemGroups();
    }

    if (next === 'audit' && audit.length === 0) {
      await loadAudit();
    }

    if (next === 'searches') {
      await loadSearches();
    }

    if (next === 'roles') {
      await loadRoles();
    }

    if (next === 'refs' && statuses.length === 0) {
      try {
        const { listStatuses, listActivityTypes } = await import('../lib/api/requests');
        statuses = await listStatuses();
        activityTypes = await listActivityTypes();
      } catch {
        // некритично
      }
    }
  }

  async function loadModules(): Promise<void> {
    modulesLoading = true;
    modulesError = '';
    modulesNotice = '';

    try {
      const data = await listModules();
      modules = data.modules;
      moduleRoles = data.roles;
    } catch (cause) {
      modulesError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить модули';
    } finally {
      modulesLoading = false;
    }
  }

  function toggleModuleEnabled(code: string): void {
    modules = modules.map((module) =>
      module.code === code ? { ...module, enabled: !module.enabled } : module
    );
    modulesNotice = '';
  }

  function toggleModuleLevel(code: string, level: number): void {
    modules = modules.map((module) =>
      module.code === code
        ? {
            ...module,
            levels: module.levels.includes(level)
              ? module.levels.filter((item) => item !== level)
              : [...module.levels, level]
          }
        : module
    );
    modulesNotice = '';
  }

  async function saveModulesDraft(): Promise<void> {
    modulesBusy = true;
    modulesError = '';
    modulesNotice = '';

    try {
      const data = await saveModules(modules);
      modules = data.modules;
      moduleRoles = data.roles;
      modulesNotice = 'Сохранено';
      await appSettings.load();
    } catch (cause) {
      modulesError = cause instanceof ApiError ? cause.message : 'Не удалось сохранить модули';
    } finally {
      modulesBusy = false;
    }
  }

  async function loadItemTypes(): Promise<void> {
    typesLoading = true;
    typesError = '';
    typesNotice = '';

    try {
      itemTypes = (await listItemTypes()).types;
    } catch (cause) {
      typesError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить типы позиций';
    } finally {
      typesLoading = false;
    }
  }

  function toggleItemType(code: string): void {
    itemTypes = itemTypes.map((type) =>
      type.code === code ? { ...type, enabled: !type.enabled } : type
    );
    typesNotice = '';
  }

  async function saveTypesDraft(): Promise<void> {
    typesBusy = true;
    typesError = '';
    typesNotice = '';

    try {
      itemTypes = (await saveItemTypes(itemTypes)).types;
      typesNotice = 'Сохранено';
    } catch (cause) {
      typesError = cause instanceof ApiError ? cause.message : 'Не удалось сохранить типы позиций';
    } finally {
      typesBusy = false;
    }
  }

  async function loadSearches(): Promise<void> {
    searchLoading = true;
    error = '';

    try {
      const data = await searchLog();
      searchStats = data.stats;
      searchTop = data.top;
      searchRecent = data.recent;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить журнал поиска';
    } finally {
      searchLoading = false;
    }
  }

  async function loadRoles(): Promise<void> {
    roleError = '';
    roleNotice = '';

    try {
      const data = await listRoles();
      roles = data.items;
      roleCatalog = data.catalog;
      roleDraft = Object.fromEntries(data.items.map((role) => [role.level, [...role.capabilities]]));
    } catch (cause) {
      roleError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить роли';
    }
  }

  function roleSelected(role: RoleInfo): string[] {
    return roleDraft[role.level] ?? role.capabilities;
  }

  function isRoleDirty(role: RoleInfo): boolean {
    const selected = roleDraft[role.level];

    if (!selected) {
      return false;
    }

    const next = [...selected].sort();
    const current = [...role.capabilities].sort();

    return next.length !== current.length || next.some((code, index) => code !== current[index]);
  }

  function toggleCapability(role: RoleInfo, code: string): void {
    const selected = roleSelected(role);
    const next = selected.includes(code)
      ? selected.filter((item) => item !== code)
      : [...selected, code];

    roleDraft = { ...roleDraft, [role.level]: next };
  }

  const capabilityGroupTitles: Record<string, string> = {
    requests: 'Заявки',
    clients: 'Клиенты',
    stocks: 'Склады',
    reports: 'Отчёты',
    chat: 'Чат',
    users: 'Пользователи',
    roles: 'Роли',
    audit: 'Аудит',
    settings: 'Настройки'
  };

  const capabilityGroupOrder = Object.keys(capabilityGroupTitles);

  const catalogGroups = $derived.by(() => {
    const groups = new Map<string, RoleCapability[]>();

    for (const capability of roleCatalog) {
      const key = capability.code.split('.')[0];
      const bucket = groups.get(key) ?? [];
      bucket.push(capability);
      groups.set(key, bucket);
    }

    return [...groups.entries()]
      .map(([key, items]) => ({
        key,
        title: capabilityGroupTitles[key] ?? key,
        items: [...items].sort((a, b) => a.title.localeCompare(b.title, 'ru'))
      }))
      .sort(
        (a, b) => capabilityGroupOrder.indexOf(a.key) - capabilityGroupOrder.indexOf(b.key)
      );
  });

  let openGroups = $state<Record<string, boolean>>({});

  function groupKey(level: number, key: string): string {
    return `${level}:${key}`;
  }

  function toggleGroup(level: number, key: string): void {
    const id = groupKey(level, key);
    openGroups = { ...openGroups, [id]: !(openGroups[id] ?? false) };
  }

  function allGroupsOpen(level: number): boolean {
    return catalogGroups.every((group) => openGroups[groupKey(level, group.key)] ?? false);
  }

  function toggleAllGroups(level: number): void {
    const next = !allGroupsOpen(level);
    const updated = { ...openGroups };

    for (const group of catalogGroups) {
      updated[groupKey(level, group.key)] = next;
    }

    openGroups = updated;
  }

  function resetRole(role: RoleInfo): void {
    roleDraft = { ...roleDraft, [role.level]: [...role.capabilities] };
  }

  async function saveRoleDraft(role: RoleInfo): Promise<void> {
    roleBusy = role.level;
    roleError = '';
    roleNotice = '';

    try {
      const data = await saveRole(role.level, roleSelected(role));
      roles = data.items;
      roleCatalog = data.catalog;
      roleDraft = {
        ...roleDraft,
        [role.level]: [...(data.items.find((item) => item.level === role.level)?.capabilities ?? [])]
      };
      roleNotice = `Права роли «${role.title}» сохранены`;
    } catch (cause) {
      roleError = cause instanceof ApiError ? cause.message : 'Не удалось сохранить права роли';
    } finally {
      roleBusy = 0;
    }
  }

  function userStateLabel(user: AdminUser): string {
    if (!user.active) {
      return user.reg_state === 'pending' ? 'ожидает' : 'заблокирован';
    }

    return 'активен';
  }
</script>

<section class="page">
  <div class="head">
    <h1>Администрирование</h1>
  </div>

  <div class="tabs tab-scroll">
    {#if auth.can('settings.manage')}
      <button type="button" class:active={tab === 'modules'} onclick={() => void openTab('modules')}>Модули</button>
      <button type="button" class:active={tab === 'types'} onclick={() => void openTab('types')}>Типы позиций</button>
    {/if}
    {#if canManageUsers}
      <button type="button" class:active={tab === 'users'} onclick={() => void openTab('users')}>Пользователи</button>
    {/if}
    {#if canViewAudit}
      <button type="button" class:active={tab === 'audit'} onclick={() => void openTab('audit')}>Журнал аудита</button>
    {/if}
    {#if canViewAudit || auth.can('settings.manage')}
      <button type="button" class:active={tab === 'searches'} onclick={() => void openTab('searches')}>Поиски позиций</button>
    {/if}
    <button type="button" class:active={tab === 'roles'} onclick={() => void openTab('roles')}>Роли</button>
    <button type="button" class:active={tab === 'refs'} onclick={() => void openTab('refs')}>Справочники</button>
    {#if auth.can('settings.manage')}
      <button type="button" class:active={tab === 'groups'} onclick={() => void openTab('groups')}>Группы позиций</button>
    {/if}
    {#if auth.can('settings.manage')}
      <button type="button" class:active={tab === 'docs'} onclick={() => void openTab('docs')}>Документы</button>
    {/if}
  </div>

  {#if error}<div class="alert">{error}</div>{/if}
  {#if message}<div class="notice">{message}</div>{/if}

  {#if tab === 'modules'}
    {#if modulesError}<div class="alert">{modulesError}</div>{/if}
    {#if modulesNotice}<div class="notice">{modulesNotice}</div>{/if}

    {#if modulesLoading}
      <div class="center"><Spinner /></div>
    {:else}
      <p class="hint">
        Выключенный модуль скрывается в интерфейсе и блокируется на сервере. «Доступно ролям» выдаёт
        роли права модуля; тонкая настройка прав — на вкладке «Роли».
      </p>

      <div class="modules">
        {#each modules as module (module.code)}
          <div class="module-card">
            <div class="module-head">
              <label class="checkbox module-toggle">
                <input
                  type="checkbox"
                  checked={module.enabled}
                  onchange={() => toggleModuleEnabled(module.code)}
                />
                <span class="module-title">{module.title}</span>
              </label>
              <code>{module.code}</code>
            </div>

            <p class="module-desc">{module.description}</p>

            <div class="module-roles">
              <span class="module-roles-label">Доступно ролям:</span>
              {#each moduleRoles as role (role.level)}
                <label class="checkbox" class:disabled={!canManageRoles}>
                  <input
                    type="checkbox"
                    checked={module.levels.includes(role.level)}
                    disabled={!canManageRoles}
                    onchange={() => toggleModuleLevel(module.code, role.level)}
                  />
                  {role.title}
                </label>
              {/each}
            </div>
          </div>
        {/each}
      </div>

      {#if !canManageRoles}
        <p class="hint">Менять доступ ролей может только сисадмин.</p>
      {/if}

      <div class="actions">
        <Button loading={modulesBusy} onclick={() => void saveModulesDraft()}>Сохранить</Button>
      </div>
    {/if}
  {/if}

  {#if tab === 'types'}
    {#if typesError}<div class="alert">{typesError}</div>{/if}
    {#if typesNotice}<div class="notice">{typesNotice}</div>{/if}

    {#if typesLoading}
      <div class="center"><Spinner /></div>
    {:else}
      <p class="hint">
        Разрешённые типы появляются в карточке номенклатуры. Хотя бы один тип должен остаться
        включённым.
      </p>

      <div class="modules">
        {#each itemTypes as type (type.code)}
          <div class="module-card">
            <div class="module-head">
              <label class="checkbox module-toggle">
                <input
                  type="checkbox"
                  checked={type.enabled}
                  onchange={() => toggleItemType(type.code)}
                />
                <span class="module-title">{type.title}</span>
              </label>
              <code>{type.code}</code>
            </div>

            <p class="module-desc">{type.description}</p>
          </div>
        {/each}
      </div>

      <div class="actions">
        <Button loading={typesBusy} onclick={() => void saveTypesDraft()}>Сохранить</Button>
      </div>
    {/if}
  {/if}

  {#if tab === 'users' && canManageUsers}
    <div class="filters">
      <form
        class="search"
        onsubmit={(event) => {
          event.preventDefault();
          submitUsersSearch();
        }}
      >
        <SearchInput
          bind:value={userQuery}
          historyKey="admin-users"
          placeholder="Поиск: имя, логин, e-mail, телефон, ИНН"
          onclear={() => {
            usersPage = 1;
            void loadUsers();
          }}
          onpick={submitUsersSearch}
        />
        <Button type="submit" variant="ghost">Найти</Button>
      </form>

      <FiltersModal
        count={countActive(userFilters, userFilterDefaults)}
        onopen={() => (userDraft = { ...userFilters })}
        onapply={applyUserFilterDraft}
        onreset={resetUserFilters}
      >
        <label class="filter-field">
          <span>Роль</span>
          <select bind:value={userDraft.level}>
            <option value={0}>Все роли</option>
            <option value={90}>Сисадмин</option>
            <option value={50}>Администратор</option>
            <option value={10}>Менеджер</option>
                <option value={5}>{appSettings.clientLabel}</option>
          </select>
        </label>

        <label class="filter-field">
          <span>Состояние</span>
          <select bind:value={userDraft.state}>
            <option value="">Все состояния</option>
            <option value="active">Активные</option>
            <option value="blocked">Заблокированные</option>
            <option value="pending">Ожидают подтверждения</option>
          </select>
        </label>
      </FiltersModal>

      <Button onclick={() => (showCreate = !showCreate)} disabled={appSettings.demoMode}>
        {showCreate ? 'Отменить' : 'Создать пользователя'}
      </Button>
    </div>

    {#if appSettings.demoMode}
      <div class="alert">В демо-режиме управление пользователями (создание, правка, пароли, блокировка) недоступно.</div>
    {/if}

    {#if showCreate && !appSettings.demoMode}
      <div class="card form">
        <h2>Новый пользователь</h2>
        <div class="grid">
          <label><span>ФИО</span><input bind:value={form.name} /></label>
          <label><span>E-mail (он же логин)</span><input bind:value={form.email} /></label>
          <label><span>Телефон</span><input bind:value={form.phone} /></label>
          <label><span>Компания</span><input bind:value={form.company} /></label>
          <label><span>ИНН</span><input bind:value={form.inn} /></label>
          <label><span>Должность</span><input bind:value={form.position} /></label>
          <label>
            <span>Роль</span>
            <select bind:value={form.level} disabled={!canManageRoles}>
                  <option value={5}>{appSettings.clientLabel}</option>
              <option value={10}>Менеджер</option>
              <option value={50}>Администратор</option>
              <option value={90}>Сисадмин</option>
            </select>
          </label>
          <label>
            <span>Тип профиля (цены)</span>
            <select bind:value={form.price_type_id}>
              <option value={0}>Не задан — цены по умолчанию</option>
              {#each priceTypes as type (type.id)}
                <option value={type.id}>{type.title}</option>
              {/each}
            </select>
          </label>
          <label>
            <span>Пароль (пусто — сгенерировать)</span>
            <input bind:value={form.password} />
          </label>
        </div>
        <div class="actions">
          <Button loading={busy} onclick={() => void submitCreate()}>Создать</Button>
        </div>
      </div>
    {/if}

    {#if editing && !appSettings.demoMode}
      <div class="card form">
        <h2>Изменение: {editing.name}</h2>
        <div class="grid">
          <label><span>ФИО</span><input bind:value={editForm.name} /></label>
          <label><span>Телефон</span><input bind:value={editForm.phone} /></label>
          <label><span>Компания</span><input bind:value={editForm.company} /></label>
          <label><span>ИНН</span><input bind:value={editForm.inn} /></label>
          <label><span>Должность</span><input bind:value={editForm.position} /></label>
          <label>
            <span>Роль</span>
            <select bind:value={editForm.level} disabled={!canManageRoles}>
                  <option value={5}>{appSettings.clientLabel}</option>
              <option value={10}>Менеджер</option>
              <option value={50}>Администратор</option>
              <option value={90}>Сисадмин</option>
            </select>
          </label>
          <label>
            <span>Тип профиля (цены)</span>
            <select bind:value={editForm.price_type_id}>
              <option value={0}>Не задан — цены по умолчанию</option>
              {#each priceTypes as type (type.id)}
                <option value={type.id}>{type.title}</option>
              {/each}
            </select>
          </label>
        </div>
        <div class="actions">
          <Button loading={busy} onclick={() => void submitEdit()}>Сохранить</Button>
          <Button variant="ghost" onclick={() => (editing = null)}>Отмена</Button>
        </div>
      </div>
    {/if}

    {#if usersLoading}
      <div class="center"><Spinner size={26} /></div>
    {:else}
      <div class="users">
        {#each users as user (user.id)}
          <div class="user" class:blocked={!user.active}>
            <div class="info">
              <div class="row">
                <span class="name">{user.name}</span>
                <span class="role">{levelTitles[user.level] ?? user.level}</span>
                <span class="state" class:off={!user.active}>{userStateLabel(user)}</span>
              </div>
              <div class="meta">
                <span>{user.login}</span>
                {#if user.company}<span>{user.company}</span>{/if}
                {#if user.inn}<span>ИНН {user.inn}</span>{/if}
                {#if user.phone}<span>{user.phone}</span>{/if}
                {#if user.price_type_title}<span>цены: {user.price_type_title}</span>{/if}
                {#if user.last_seen_at}<span>был(а) {formatDateTime(user.last_seen_at)}</span>{/if}
              </div>
            </div>
            <div class="row-actions">
              {#if !appSettings.demoMode}
                <Button variant="ghost" onclick={() => startEdit(user)}>Изменить</Button>
                <Button variant="ghost" onclick={() => void resetPassword(user)}>Сбросить пароль</Button>
                <Button variant="ghost" onclick={() => void toggleBlock(user)}>
                  {user.active ? 'Заблокировать' : 'Разблокировать'}
                </Button>
              {/if}
            </div>
          </div>
        {/each}
      </div>

      <div class="pager">
        <Button variant="ghost" disabled={usersPage <= 1} onclick={() => { usersPage -= 1; void loadUsers(); }}>
          Назад
        </Button>
        <span>Стр. {usersPage} · всего {usersTotal}</span>
        <Button
          variant="ghost"
          disabled={usersPage * 50 >= usersTotal}
          onclick={() => { usersPage += 1; void loadUsers(); }}
        >
          Вперёд
        </Button>
      </div>
    {/if}
  {/if}

  {#if tab === 'audit'}
    <div class="filters">
      <SearchInput
        bind:value={auditAction}
        historyKey="admin-audit"
        placeholder="Действие по коду (например, request.transition)"
        onclear={applyAuditSearch}
        onpick={applyAuditSearch}
      />
      <Button variant="ghost" onclick={applyAuditSearch}>Показать</Button>

      <FiltersModal
        count={countActive(auditFilters, auditFilterDefaults)}
        onopen={() => (auditDraft = { ...auditFilters })}
        onapply={applyAuditDraft}
        onreset={resetAuditFilters}
      >
        <label class="filter-field">
          <span>Период с</span>
          <input type="date" bind:value={auditDraft.from} max={auditDraft.to || todayIso()} />
        </label>
        <label class="filter-field">
          <span>Период по</span>
          <input type="date" bind:value={auditDraft.to} min={auditDraft.from} max={todayIso()} />
        </label>
      </FiltersModal>
    </div>

    {#if auditLoading}
      <div class="center"><Spinner size={26} /></div>
    {:else if audit.length === 0}
      <div class="empty">Записей нет</div>
    {:else}
      <div class="audit">
        {#each audit as entry (entry.id)}
          <div class="entry">
            <div class="row">
              <span class="action">{auditLabel(entry.action)}</span>
              <span class="when">{formatDateTime(entry.created_at)}</span>
            </div>
            <div class="meta">
              {#if entry.user}<span>{entry.user.name}</span>{/if}
              {#if entry.entity}<span>{entityLabel(entry.entity)} #{entry.entity_id}</span>{/if}
              {#if entry.ip}<span>{entry.ip}</span>{/if}
              <span class="action-code">{entry.action}</span>
            </div>
            {#if Object.keys(entry.data).length > 0}
              <div class="data">
                {#each Object.entries(entry.data) as [key, value]}
                  <span>{key}: {String(value)}</span>
                {/each}
              </div>
            {/if}
          </div>
        {/each}
      </div>

      <div class="pager">
        <Button variant="ghost" disabled={auditPage <= 1} onclick={() => { auditPage -= 1; void loadAudit(); }}>
          Назад
        </Button>
        <span>Стр. {auditPage} · всего {auditTotal}</span>
        <Button
          variant="ghost"
          disabled={auditPage * 50 >= auditTotal}
          onclick={() => { auditPage += 1; void loadAudit(); }}
        >
          Вперёд
        </Button>
      </div>
    {/if}
  {/if}

  {#if tab === 'searches'}
    {#if searchLoading}
      <div class="center"><Spinner size={26} /></div>
    {:else}
      <div class="search-stats">
        <div class="stat"><span class="value">{searchStats.total}</span><span class="label">запросов всего</span></div>
        <div class="stat"><span class="value">{searchStats.unique_queries}</span><span class="label">уникальных запросов</span></div>
        <div class="stat"><span class="value">{searchStats.users}</span><span class="label">пользователей</span></div>
      </div>

      <div class="search-card">
        <h2>Частые запросы</h2>
        {#if searchTop.length === 0}
          <p class="empty">Пока нет данных</p>
        {:else}
          <div class="search-table">
            {#each searchTop as row (row.query)}
              <div class="search-row">
                <span class="query">{row.query}</span>
                <span class="count">{row.searches}</span>
                <span class="when">{formatDateTime(row.last_at)}</span>
              </div>
            {/each}
          </div>
        {/if}
      </div>

      <div class="search-card">
        <h2>Последние поиски</h2>
        {#if searchRecent.length === 0}
          <p class="empty">Пока нет данных</p>
        {:else}
          <div class="search-table">
            {#each searchRecent as row (row.id)}
              <div class="search-row">
                <span class="query">{row.query}</span>
                <span class="count">{row.results}</span>
                <span class="who">{row.user_name}</span>
                <span class="when">{formatDateTime(row.created_at)}</span>
              </div>
            {/each}
          </div>
        {/if}
      </div>
    {/if}
  {/if}

  {#if tab === 'docs'}
    <div class="docs">
      <div class="doc-tabs tab-scroll">
        {#each docs as document (document.code)}
          <button
            type="button"
            class:active={document.code === docCode}
            onclick={() => selectDoc(document)}
          >
            {document.title}
          </button>
        {/each}
      </div>

      {#if docCode}
        <div class="card form">
          <label><span>Заголовок</span><input bind:value={docTitle} /></label>
          <label>
            <span>Текст документа (редактируется администратором)</span>
            <textarea bind:value={docBody} rows="18"></textarea>
          </label>
          {#if docPlaceholders.length > 0}
            <div class="alert">
              В документе остались незаполненные реквизиты:
              {docPlaceholders.join(', ')}. Замените их данными оператора — для 152-ФЗ политика
              должна содержать наименование, адрес и контакты.
            </div>
          {/if}
          {#if docNotice}<div class="notice">{docNotice}</div>{/if}
          <div class="actions">
            <Button loading={docBusy} onclick={() => void saveDoc()}>Сохранить</Button>
            <Button variant="ghost" onclick={() => router.navigate(`/legal/${docCode}`)}>Открыть страницу</Button>
          </div>
        </div>
      {/if}
    </div>
  {/if}

  {#if tab === 'groups'}
    <div class="card form">
      <h2>Группы позиций</h2>
      <p class="hint">
        Группы включаются настройкой «Разрешить использовать группы позиций» в «Настройках». Группа
        назначается позиции при редактировании на складе; по группам можно фильтровать остатки и
        выбор позиций в заявке.
      </p>

      {#if itemGroups.length === 0}
        <p class="hint">Групп пока нет — добавьте первую.</p>
      {:else}
        <div class="groups">
          {#each itemGroups as group (group.id)}
            <div class="group-row">
              <input class="group-title" bind:value={groupDrafts[group.id]} maxlength="255" />
              <span class="group-meta">{group.positions_count ?? 0} поз.</span>
              <Button variant="ghost" disabled={groupsBusy} onclick={() => void saveGroup(group)}>
                Сохранить
              </Button>
              <button
                type="button"
                class="group-del"
                disabled={groupsBusy}
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
          <input bind:value={newGroupTitle} placeholder="Например: Смартфоны" maxlength="255" />
        </label>
        <Button
          variant="ghost"
          loading={groupsBusy}
          disabled={!newGroupTitle.trim()}
          onclick={() => void addGroup()}
        >
          Добавить группу
        </Button>
      </div>
    </div>
  {/if}

  {#if tab === 'refs'}
    <div class="card">
      <h2>Статусы заявок</h2>
      <div class="refs">
        {#each statuses as status (status.code)}
          <div class="ref">
            <span class="ref-title" style="color: {status.color}">{status.title}</span>
            <span class="ref-code">{status.code}{status.is_final ? ' · финальный' : ''}</span>
          </div>
        {/each}
      </div>
    </div>

    <div class="card">
      <h2>Типы действий</h2>
      <div class="refs">
        {#each activityTypes as type (type.code)}
          <div class="ref">
            <span class="ref-title">{type.title}</span>
            <span class="ref-code">{type.code} · {type.audience === 'manager' ? 'менеджер' : type.audience === 'client' ? 'клиент' : 'все'}</span>
          </div>
        {/each}
      </div>
    </div>
  {/if}

  {#if tab === 'roles'}
    {#if roleError}
      <div class="alert">{roleError}</div>
    {/if}
    {#if roleNotice}
      <div class="notice">{roleNotice}</div>
    {/if}

    <div class="roles">
      {#each roles as role (role.level)}
        {@const selected = roleSelected(role)}
        <div class="card">
          <div class="role-head">
            <h2>{role.title} <span class="level">уровень {role.level}</span></h2>
            {#if canManageRoles}
              <div class="role-actions">
                <span class="role-count">{selected.length} из {roleCatalog.length}</span>
                <Button variant="ghost" onclick={() => toggleAllGroups(role.level)}>
                  {allGroupsOpen(role.level) ? 'Свернуть всё' : 'Развернуть всё'}
                </Button>
                <Button variant="ghost" disabled={!isRoleDirty(role)} onclick={() => resetRole(role)}>
                  Сбросить
                </Button>
                <Button
                  loading={roleBusy === role.level}
                  disabled={!isRoleDirty(role)}
                  onclick={() => void saveRoleDraft(role)}
                >
                  Сохранить
                </Button>
              </div>
            {/if}
          </div>

          {#if canManageRoles}
            <div class="caps-edit">
              {#each catalogGroups as group (group.key)}
                {@const isOpen = openGroups[groupKey(role.level, group.key)] ?? false}
                {@const groupSelected = group.items.filter((item) => selected.includes(item.code)).length}
                <div class="cap-group" class:open={isOpen}>
                  <button
                    type="button"
                    class="cap-group-head"
                    aria-expanded={isOpen}
                    onclick={() => toggleGroup(role.level, group.key)}
                  >
                    <span class="chev" class:open={isOpen} aria-hidden="true"></span>
                    <span class="cap-group-title">{group.title}</span>
                    <span class="cap-group-count">{groupSelected} из {group.items.length}</span>
                  </button>

                  {#if isOpen}
                    <div class="cap-group-body">
                      {#each group.items as capability (capability.code)}
                        {@const core = role.level >= 90 && adminCoreCapabilities.includes(capability.code)}
                        <label class="cap-check" class:core title={core ? 'Обязательное право администратора' : ''}>
                          <input
                            type="checkbox"
                            checked={selected.includes(capability.code)}
                            disabled={core}
                            onchange={() => toggleCapability(role, capability.code)}
                          />
                          <span class="cap-title">{capability.title}</span>
                          <code>{capability.code}</code>
                        </label>
                      {/each}
                    </div>
                  {/if}
                </div>
              {/each}
            </div>
          {:else}
            <div class="caps">
              {#each role.capabilities as capability (capability)}
                <span class="cap">{capability}</span>
              {/each}
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

  .checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: var(--text);
    cursor: pointer;
  }

  .checkbox.disabled {
    color: var(--text-description);
    cursor: not-allowed;
  }

  .modules {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .module-card {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
  }

  .module-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
  }

  .module-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
  }

  .module-head code {
    font-size: 12px;
    color: var(--text-description);
    background: var(--fill-tertiary);
    border-radius: 4px;
    padding: 1px 6px;
  }

  .module-desc {
    margin: 0;
    font-size: 13px;
    color: var(--text-description);
  }

  .module-roles {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-3);
  }

  .module-roles-label {
    font-size: 13px;
    color: var(--text-description);
  }

  h1 {
    margin: 0;
    font-size: 22px;
  }

  h2 {
    margin: 0 0 var(--space-2);
    font-size: 16px;
  }

  .tabs {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .tabs button {
    padding: 7px 16px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--muted);
    cursor: pointer;
    font: inherit;
    font-size: 13px;
  }

  .tabs button.active {
    border-color: var(--primary);
    color: var(--primary);
    font-weight: 500;
  }

  .filters {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    align-items: center;
  }

  .search {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    flex: 1 1 260px;
    min-width: 0;
    max-width: 420px;
  }

  @media (max-width: 720px) {
    .search {
      flex: 1 1 100%;
      max-width: none;
    }
  }

  .filters select {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
  }


  .card {
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
  }

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
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

  label input,
  label select,
  label textarea {
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font: inherit;
    background: var(--surface);
    color: var(--text);
  }

  label textarea {
    resize: vertical;
    line-height: 1.5;
  }

  .docs {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .doc-tabs {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .doc-tabs button {
    padding: 7px 16px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--muted);
    cursor: pointer;
    font: inherit;
    font-size: 13px;
  }

  .doc-tabs button.active {
    border-color: var(--primary);
    color: var(--primary);
    font-weight: 500;
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .hint {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
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
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: var(--space-2) var(--space-3);
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
    background: rgba(220, 38, 38, 0.06);
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
  }

  .group-add label {
    flex: 1;
    min-width: 200px;
  }

  .users {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .user {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: var(--space-3) var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    transition: background 0.12s ease, border-color 0.12s ease;
  }

  .user:hover {
    background: var(--bg);
    border-color: var(--primary);
  }

  .user.blocked {
    opacity: 0.7;
  }

  .row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .name {
    font-weight: 500;
  }

  .role {
    font-size: 12px;
    color: var(--muted);
    border: 1px solid var(--border);
    border-radius: 999px;
    padding: 1px 8px;
  }

  .state {
    font-size: 12px;
    color: #1e6b3a;
  }

  .state.off {
    color: #8c1d18;
  }

  .meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    font-size: 12px;
    color: var(--muted);
    margin-top: 2px;
  }

  .row-actions {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .audit {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .entry {
    padding: var(--space-3);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    transition: background 0.12s ease;
  }

  .entry:hover {
    background: var(--bg);
  }

  .entry .action {
    font-weight: 500;
    font-size: 14px;
  }

  .entry .action-code {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 11px;
    opacity: 0.75;
  }

  .entry .when {
    font-size: 12px;
    color: var(--muted);
  }

  .data {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    margin-top: 4px;
    font-size: 12px;
    color: var(--muted);
  }

  .roles {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .refs {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
  }

  .ref {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
  }

  .ref-title {
    font-size: 14px;
    font-weight: 500;
  }

  .ref-code {
    font-size: 12px;
    color: var(--muted);
  }

  .level {
    font-size: 12px;
    color: var(--muted);
    font-weight: 400;
  }

  .caps {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }

  .cap {
    font-size: 12px;
    padding: 2px 8px;
    border: 1px solid var(--border);
    border-radius: 999px;
    color: var(--muted);
  }

  .role-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-3);
    flex-wrap: wrap;
    margin-bottom: var(--space-2);
  }

  .role-actions {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
  }

  .role-count {
    font-size: 12px;
    color: var(--muted);
  }

  .caps-edit {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .cap-group {
    border-bottom: 1px solid var(--border);
  }

  .cap-group:last-child {
    border-bottom: none;
  }

  .cap-group-head {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 8px 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
  }

  .cap-group-head:hover {
    color: var(--primary);
  }

  .cap-group-title {
    flex: 1;
    font-weight: 500;
    font-size: 13px;
  }

  .cap-group-count {
    font-size: 12px;
    color: var(--muted);
  }

  .chev {
    width: 7px;
    height: 7px;
    border: solid currentColor;
    border-width: 0 1.5px 1.5px 0;
    transform: rotate(-45deg);
    transition: transform 0.15s ease;
  }

  .chev.open {
    transform: rotate(45deg);
  }

  .cap-group-body {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 6px 16px;
    padding: 2px 0 10px 17px;
  }

  .cap-check {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    padding: 4px 0;
    cursor: pointer;
  }

  .cap-check.core {
    cursor: not-allowed;
    opacity: 0.75;
  }

  .cap-check input {
    flex: none;
  }

  .cap-title {
    flex: 1;
  }

  .cap-check code {
    font-size: 11px;
    color: var(--muted);
  }

  .pager {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-3);
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

  .notice {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: #e7f5ec;
    color: #1e6b3a;
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

  .search-stats {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
  }

  .search-stats .stat {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: var(--space-3) var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
  }

  .search-stats .value {
    font-size: 22px;
    font-weight: 600;
  }

  .search-stats .label {
    font-size: 12px;
    color: var(--muted);
  }

  .search-card {
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
  }

  .search-table {
    display: flex;
    flex-direction: column;
  }

  .search-row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
  }

  .search-row:last-child {
    border-bottom: none;
  }

  .search-row .query {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .search-row .count {
    font-weight: 600;
    color: var(--primary);
    white-space: nowrap;
  }

  .search-row .who,
  .search-row .when {
    color: var(--muted);
    font-size: 13px;
    white-space: nowrap;
  }
</style>
