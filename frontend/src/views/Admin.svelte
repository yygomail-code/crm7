<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import {
    blockUser,
    createUser,
    listAudit,
    listRoles,
    listUsers,
    resetUserPassword,
    unblockUser,
    updateUser
  } from '../lib/api/admin';
  import { listLegalDocuments, saveLegalDocument, type LegalDocument } from '../lib/api/legal';
  import type { AdminUser, AuditEntry, RoleInfo } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import { router } from '../lib/router.svelte';
  import { addHistory } from '../lib/search-history';
  import Button from '../lib/components/ui/Button.svelte';
  import SearchInput from '../lib/components/ui/SearchInput.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';
  import { formatDateTime } from '../lib/format';

  const levelTitles: Record<number, string> = {
    90: 'Сисадмин',
    50: 'Администратор',
    10: 'Менеджер',
    5: 'Клиент',
    1: 'Гость'
  };

  let tab = $state<'users' | 'audit' | 'roles' | 'refs' | 'docs'>('users');

  let users = $state<AdminUser[]>([]);
  let usersTotal = $state(0);
  let usersPage = $state(1);
  let userQuery = $state('');
  let userLevel = $state(0);
  let userState = $state('');
  let usersLoading = $state(true);

  let editing = $state<AdminUser | null>(null);
  let showCreate = $state(false);
  let form = $state({
    name: '',
    email: '',
    phone: '',
    company: '',
    inn: '',
    position: '',
    level: 5,
    password: ''
  });
  let editForm = $state({ name: '', phone: '', company: '', inn: '', position: '', level: 5 });

  let audit = $state<AuditEntry[]>([]);
  let auditTotal = $state(0);
  let auditPage = $state(1);
  let auditAction = $state('');
  let auditFrom = $state('');
  let auditTo = $state('');
  let auditLoading = $state(false);

  let roles = $state<RoleInfo[]>([]);
  let statuses = $state<{ code: string; title: string; sort: number; color: string; is_final: boolean }[]>([]);
  let activityTypes = $state<{ code: string; title: string; audience: string; sort: number }[]>([]);
  let docs = $state<LegalDocument[]>([]);
  let docCode = $state('');
  let docTitle = $state('');
  let docBody = $state('');
  let docBusy = $state(false);
  let docNotice = $state('');

  let error = $state('');
  let message = $state('');
  let busy = $state(false);

  const canManageUsers = $derived(auth.can('users.manage'));
  const canViewAudit = $derived(auth.can('audit.view'));
  const canManageRoles = $derived(auth.can('roles.manage'));

  onMount(() => {
    if (canManageUsers) {
      void loadUsers();
    } else if (canViewAudit) {
      tab = 'audit';
      void loadAudit();
    }
  });

  async function loadUsers(): Promise<void> {
    usersLoading = true;
    error = '';

    try {
      const data = await listUsers({
        q: userQuery,
        level: userLevel,
        state: userState,
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

  function applyAuditFilters(): void {
    auditPage = 1;
    addHistory('admin-audit', auditAction);
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

      form = { name: '', email: '', phone: '', company: '', inn: '', position: '', level: 5, password: '' };
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
      level: user.level
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
        from: auditFrom,
        to: auditTo,
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

  async function openTab(next: 'users' | 'audit' | 'roles' | 'refs' | 'docs'): Promise<void> {
    tab = next;

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

    if (next === 'audit' && audit.length === 0) {
      await loadAudit();
    }

    if (next === 'roles' && roles.length === 0) {
      try {
        roles = (await listRoles()).items;
      } catch {
        // некритично
      }
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

  <div class="tabs">
    {#if canManageUsers}
      <button type="button" class:active={tab === 'users'} onclick={() => void openTab('users')}>Пользователи</button>
    {/if}
    {#if canViewAudit}
      <button type="button" class:active={tab === 'audit'} onclick={() => void openTab('audit')}>Журнал аудита</button>
    {/if}
    <button type="button" class:active={tab === 'roles'} onclick={() => void openTab('roles')}>Роли</button>
    <button type="button" class:active={tab === 'refs'} onclick={() => void openTab('refs')}>Справочники</button>
    {#if auth.can('settings.manage')}
      <button type="button" class:active={tab === 'docs'} onclick={() => void openTab('docs')}>Документы</button>
    {/if}
  </div>

  {#if error}<div class="alert">{error}</div>{/if}
  {#if message}<div class="notice">{message}</div>{/if}

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

      <select bind:value={userLevel} onchange={() => { usersPage = 1; void loadUsers(); }}>
        <option value={0}>Все роли</option>
        <option value={90}>Сисадмин</option>
        <option value={50}>Администратор</option>
        <option value={10}>Менеджер</option>
        <option value={5}>Клиент</option>
      </select>

      <select bind:value={userState} onchange={() => { usersPage = 1; void loadUsers(); }}>
        <option value="">Все состояния</option>
        <option value="active">Активные</option>
        <option value="blocked">Заблокированные</option>
        <option value="pending">Ожидают подтверждения</option>
      </select>

      <Button onclick={() => (showCreate = !showCreate)}>
        {showCreate ? 'Отменить' : 'Создать пользователя'}
      </Button>
    </div>

    {#if showCreate}
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
              <option value={5}>Клиент</option>
              <option value={10}>Менеджер</option>
              <option value={50}>Администратор</option>
              <option value={90}>Сисадмин</option>
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

    {#if editing}
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
              <option value={5}>Клиент</option>
              <option value={10}>Менеджер</option>
              <option value={50}>Администратор</option>
              <option value={90}>Сисадмин</option>
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
                {#if user.last_seen_at}<span>был(а) {formatDateTime(user.last_seen_at)}</span>{/if}
              </div>
            </div>
            <div class="row-actions">
              <Button variant="ghost" onclick={() => startEdit(user)}>Изменить</Button>
              <Button variant="ghost" onclick={() => void resetPassword(user)}>Сбросить пароль</Button>
              <Button variant="ghost" onclick={() => void toggleBlock(user)}>
                {user.active ? 'Заблокировать' : 'Разблокировать'}
              </Button>
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
        placeholder="Действие (например, request.transition)"
        onclear={applyAuditFilters}
        onpick={applyAuditFilters}
      />
      <label class="date"><span>с</span><input type="date" bind:value={auditFrom} /></label>
      <label class="date"><span>по</span><input type="date" bind:value={auditTo} /></label>
      <Button variant="ghost" onclick={applyAuditFilters}>Показать</Button>
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
              <span class="action">{entry.action}</span>
              <span class="when">{formatDateTime(entry.created_at)}</span>
            </div>
            <div class="meta">
              {#if entry.user}<span>{entry.user.name}</span>{/if}
              {#if entry.entity}<span>{entry.entity} #{entry.entity_id}</span>{/if}
              {#if entry.ip}<span>{entry.ip}</span>{/if}
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

  {#if tab === 'docs'}
    <div class="docs">
      <div class="doc-tabs">
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
          {#if docNotice}<div class="notice">{docNotice}</div>{/if}
          <div class="actions">
            <Button loading={docBusy} onclick={() => void saveDoc()}>Сохранить</Button>
            <Button variant="ghost" onclick={() => router.navigate(`/legal/${docCode}`)}>Открыть страницу</Button>
          </div>
        </div>
      {/if}
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
      <h2>Типы активностей</h2>
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
    <div class="roles">
      {#each roles as role (role.level)}
        <div class="card">
          <h2>{role.title} <span class="level">уровень {role.level}</span></h2>
          <div class="caps">
            {#each role.capabilities as capability (capability)}
              <span class="cap">{capability}</span>
            {/each}
          </div>
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
    gap: var(--space-2);
    flex: 1;
    min-width: 220px;
    max-width: 420px;
  }

  .filters select {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
  }

  .date {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--muted);
  }

  .date input {
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
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
    gap: var(--space-2);
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
  }

  .entry .action {
    font-weight: 500;
    font-size: 14px;
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
</style>
