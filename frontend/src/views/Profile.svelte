<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError, apiRequest } from '../lib/api/client';
  import { deleteAvatar, updateProfile, uploadAvatar } from '../lib/api/profile';
  import { avatarColor, avatarLetter, avatarName } from '../lib/avatar';
  import {
    getNotificationSettings,
    saveNotificationSettings,
    type NotificationSettingItem
  } from '../lib/api/notifications';
  import { auth } from '../lib/stores/auth.svelte';
  import { avatars } from '../lib/stores/avatar.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Input from '../lib/components/ui/Input.svelte';

  let tab = $state<'profile' | 'notifications' | 'security' | 'logout'>('profile');
  let logoutBusy = $state('');
  let logoutNotice = $state('');
  let logoutError = $state('');

  let name = $state(auth.name);
  let position = $state(auth.user?.position ?? '');
  let phone = $state(auth.user?.phone ?? '');
  let email = $state(auth.user?.email ?? '');
  const isStaff = $derived(auth.level >= 10);
  let profileBusy = $state(false);
  let profileNotice = $state('');
  let profileError = $state('');

  const avatarUrl = $derived(avatars.url(auth.user?.id));
  let avatarBusy = $state(false);
  let avatarInput: HTMLInputElement | null = $state(null);

  let settings = $state<NotificationSettingItem[]>([]);
  let settingsBusy = $state(false);
  let settingsNotice = $state('');

  let current = $state('');
  let next = $state('');
  let confirmation = $state('');
  let error = $state('');
  let success = $state('');
  let loading = $state(false);

  const avatarSeed = $derived(avatarName(auth.user?.name, auth.user?.login ?? 'guest'));
  const letter = $derived(avatarLetter(avatarSeed));
  const avatarBg = $derived(avatarColor(avatarSeed));

  onMount(() => {
    if (auth.user) {
      void avatars.load(auth.user.id);
    }

    void getNotificationSettings()
      .then((data) => {
        settings = data.items;
      })
      .catch(() => {
        settings = [];
      });
  });

  async function saveName(): Promise<void> {
    profileBusy = true;
    profileNotice = '';
    profileError = '';

    try {
      const result = await updateProfile(
        isStaff
          ? {
              name: name.trim(),
              position: position.trim(),
              phone: phone.trim(),
              email: email.trim()
            }
          : { name: name.trim() }
      );

      if (auth.user) {
        auth.user.name = result.name;
        auth.user.position = result.position;
        auth.user.phone = result.phone;
        auth.user.email = result.email;
      }

      profileNotice = 'Данные сохранены';
    } catch (cause) {
      profileError = cause instanceof ApiError ? cause.message : 'Не удалось сохранить данные';
    } finally {
      profileBusy = false;
    }
  }

  async function onAvatarSelected(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file || !auth.user) {
      return;
    }

    avatarBusy = true;
    profileError = '';
    profileNotice = '';

    try {
      await uploadAvatar(file);
      await avatars.refresh(auth.user.id);
      profileNotice = 'Фото обновлено';
    } catch (cause) {
      profileError = cause instanceof ApiError ? cause.message : 'Не удалось загрузить фото';
    } finally {
      avatarBusy = false;

      if (avatarInput) {
        avatarInput.value = '';
      }
    }
  }

  async function removeAvatar(): Promise<void> {
    if (!auth.user || !confirm('Удалить фото профиля?')) {
      return;
    }

    avatarBusy = true;
    profileError = '';
    profileNotice = '';

    try {
      await deleteAvatar();
      await avatars.refresh(auth.user.id);
      profileNotice = 'Фото удалено';
    } catch (cause) {
      profileError = cause instanceof ApiError ? cause.message : 'Не удалось удалить фото';
    } finally {
      avatarBusy = false;
    }
  }

  async function saveSettings(): Promise<void> {
    settingsBusy = true;
    settingsNotice = '';

    try {
      settings = (await saveNotificationSettings(settings)).items;
      settingsNotice = 'Настройки уведомлений сохранены';
    } catch (cause) {
      settingsNotice = cause instanceof ApiError ? cause.message : 'Не удалось сохранить';
    } finally {
      settingsBusy = false;
    }
  }

  async function logoutCurrent(): Promise<void> {
    if (!confirm('Выйти из текущей сессии?')) {
      return;
    }

    logoutBusy = 'current';
    await auth.logout();
    logoutBusy = '';
  }

  async function logoutOthers(): Promise<void> {
    logoutBusy = 'others';
    logoutNotice = '';
    logoutError = '';

    try {
      const revoked = await auth.logoutOthers();

      logoutNotice = revoked > 0
        ? `Завершено других сессий: ${revoked}`
        : 'Других активных сессий не было';
    } catch (cause) {
      logoutError = cause instanceof ApiError ? cause.message : 'Не удалось завершить другие сессии';
    } finally {
      logoutBusy = '';
    }
  }

  async function logoutAll(): Promise<void> {
    if (!confirm('Выйти из всех сессий на всех устройствах?')) {
      return;
    }

    logoutBusy = 'all';
    await auth.logoutAll();
    logoutBusy = '';
  }

  async function submitPassword(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    error = '';
    success = '';

    if (next !== confirmation) {
      error = 'Пароли не совпадают';
      return;
    }

    loading = true;

    try {
      await apiRequest('/auth/password/change', {
        method: 'POST',
        auth: true,
        body: { current_password: current, new_password: next }
      });

      success = 'Пароль изменён. Остальные сессии завершены.';
      current = '';
      next = '';
      confirmation = '';
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось изменить пароль';
    } finally {
      loading = false;
    }
  }
</script>

<section class="page" class:profile-layout={tab === 'profile'}>
  <h1>Профиль</h1>

  <div class="tabs tab-scroll">
    <button type="button" class:active={tab === 'profile'} onclick={() => (tab = 'profile')}>
      Профиль
    </button>
    <button
      type="button"
      class:active={tab === 'notifications'}
      onclick={() => (tab = 'notifications')}
    >
      Уведомления
    </button>
    <button type="button" class:active={tab === 'security'} onclick={() => (tab = 'security')}>
      Безопасность
    </button>
    <button type="button" class:active={tab === 'logout'} onclick={() => (tab = 'logout')}>
      Сессии
    </button>
  </div>

  {#if tab === 'profile'}
    {#if profileError}<div class="alert error">{profileError}</div>{/if}
    {#if profileNotice}<div class="alert success">{profileNotice}</div>{/if}

    <div class="card avatar-card">
      <div class="avatar-big">
        {#if avatarUrl}
          <img src={avatarUrl} alt="" />
        {:else}
          <span style:background={avatarBg}>{letter}</span>
        {/if}
      </div>

      <div class="avatar-actions">
        <label class="upload">
          <input
            bind:this={avatarInput}
            type="file"
            accept=".jpg,.jpeg,.png,.webp"
            onchange={onAvatarSelected}
            disabled={avatarBusy}
          />
          <span>{avatarBusy ? 'Загрузка…' : avatarUrl ? 'Заменить фото' : 'Добавить фото'}</span>
        </label>
        {#if avatarUrl}
          <Button variant="ghost" disabled={avatarBusy} onclick={() => void removeAvatar()}>Удалить фото</Button>
        {/if}
        <p class="hint">jpg, png или webp, до 5 МБ</p>
      </div>
    </div>

    <form
      class="card form"
      onsubmit={(event) => {
        event.preventDefault();
        void saveName();
      }}
    >
      <h2>Личные данные</h2>

      <Input label="Имя" bind:value={name} autocomplete="name" />

      {#if isStaff}
        <Input label="Должность" bind:value={position} autocomplete="organization-title" />
        <Input label="Телефон" bind:value={phone} autocomplete="tel" />
        <Input label="E-mail" type="email" bind:value={email} autocomplete="email" />

        <div class="readonly">
          <div><span>Логин</span><strong>{auth.user?.login ?? '—'}</strong></div>
          <div><span>Компания</span><strong>{auth.user?.company || '—'}</strong></div>
        </div>
      {:else}
        <div class="readonly">
          <div><span>Логин</span><strong>{auth.user?.login ?? '—'}</strong></div>
          <div><span>E-mail</span><strong>{auth.user?.email || '—'}</strong></div>
          <div><span>Телефон</span><strong>{auth.user?.phone || '—'}</strong></div>
          <div><span>Компания</span><strong>{auth.user?.company || '—'}</strong></div>
        </div>
        <p class="hint">
          E-mail, телефон и компанию меняет ваш менеджер — напишите ему в чат или попросите в
          комментарии к заявке.
        </p>
      {/if}

      <Button type="submit" loading={profileBusy} disabled={name.trim().length < 3}>Сохранить</Button>
    </form>

  {/if}

  {#if tab === 'notifications'}
    {#if settings.length > 0}
      <div class="card">
        <h2>Уведомления</h2>

        <div class="notify">
          <div class="notify-head">
            <span>Событие</span>
            <span>В системе</span>
            <span>На e-mail</span>
          </div>
          {#each settings as item (item.event)}
            <div class="notify-row">
              <span class="notify-title">{item.title}</span>
              <label><input type="checkbox" bind:checked={item.in_app} /></label>
              <label><input type="checkbox" bind:checked={item.email} /></label>
            </div>
          {/each}
        </div>

        {#if settingsNotice}
          <div class="alert success">{settingsNotice}</div>
        {/if}

        <Button loading={settingsBusy} onclick={() => void saveSettings()}>Сохранить уведомления</Button>
      </div>
    {:else}
      <div class="card"><p class="hint">Настройки уведомлений недоступны.</p></div>
    {/if}
  {/if}

  {#if tab === 'security'}
    <form class="card form" onsubmit={submitPassword}>
      <h2>Смена пароля</h2>

      <Input label="Текущий пароль" type="password" bind:value={current} autocomplete="current-password" />
      <Input label="Новый пароль" type="password" bind:value={next} autocomplete="new-password" />
      <Input label="Повторите новый пароль" type="password" bind:value={confirmation} autocomplete="new-password" />

      <p class="hint">Пароль: не менее 10 символов, буквы и цифры.</p>

      {#if error}
        <div class="alert error">{error}</div>
      {/if}

      {#if success}
        <div class="alert success">{success}</div>
      {/if}

      <Button type="submit" loading={loading} disabled={!current || !next || !confirmation}>
        Изменить пароль
      </Button>
    </form>
  {/if}

  {#if tab === 'logout'}
    <div class="card">
      <h2>Сессии и выход</h2>
      <p class="hint">
        Если вы работали с нескольких устройств, можно завершить только текущую сессию, все сессии кроме
        текущей или все сразу — например, если входили с чужого компьютера.
      </p>

      {#if logoutNotice}<div class="alert success">{logoutNotice}</div>{/if}
      {#if logoutError}<div class="alert error">{logoutError}</div>{/if}

      <div class="logout-actions">
        <Button variant="ghost" loading={logoutBusy === 'current'} onclick={() => void logoutCurrent()}>
          Выйти из текущей сессии
        </Button>
        <Button variant="ghost" loading={logoutBusy === 'others'} onclick={() => void logoutOthers()}>
          Выйти из всех сессий, кроме текущей
        </Button>
        <Button variant="danger" loading={logoutBusy === 'all'} onclick={() => void logoutAll()}>
          Выйти из всех сессий
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
    max-width: 960px;
    margin: 0 auto;
  }

  @media (min-width: 900px) {
    .page.profile-layout {
      display: grid;
      grid-template-columns: 280px 1fr;
      align-items: start;
    }

    .page.profile-layout > h1,
    .page.profile-layout > .tabs,
    .page.profile-layout > .alert {
      grid-column: 1 / -1;
    }
  }

  h1 {
    margin: 0;
    font-size: 22px;
  }

  h2 {
    margin: 0 0 var(--space-3);
    font-size: 16px;
  }

  .tabs {
    display: flex;
    gap: var(--space-2);
  }

  .tabs button {
    padding: 7px 18px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--muted);
    cursor: pointer;
    font: inherit;
    font-size: 14px;
  }

  .tabs button.active {
    border-color: var(--primary);
    color: var(--primary);
    font-weight: 500;
  }

  .card {
    padding: var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
  }

  .avatar-card {
    display: flex;
    align-items: center;
    gap: var(--space-4);
  }

  .avatar-big {
    width: 88px;
    height: 88px;
    flex: 0 0 auto;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: color-mix(in srgb, var(--primary) 10%, white);
    color: var(--primary);
    font-size: 28px;
    font-weight: 600;
    overflow: hidden;
    border: 1px solid var(--border);
  }

  .avatar-big img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .avatar-big span {
    display: grid;
    place-items: center;
    width: 100%;
    height: 100%;
    color: #fff;
  }

  .avatar-actions {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .upload {
    display: inline-flex;
    align-items: center;
    padding: 8px 16px;
    border: 1px solid var(--primary);
    border-radius: var(--radius-sm);
    color: var(--primary);
    cursor: pointer;
    font-size: 13px;
    width: fit-content;
  }

  .upload input {
    display: none;
  }

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .readonly {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--space-3);
  }

  .readonly div {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .readonly span {
    font-size: 12px;
    color: var(--muted);
  }

  .readonly strong {
    font-weight: 500;
    font-size: 14px;
  }

  .alert {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    font-size: 13px;
  }

  .error {
    background: var(--danger-bg);
    color: var(--danger);
  }

  .success {
    background: #ecfdf5;
    color: #047857;
  }

  .logout-actions {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-2);
  }

  .hint {
    margin: 0;
    color: var(--muted);
    font-size: 12px;
  }

  .notify {
    display: flex;
    flex-direction: column;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    overflow: hidden;
    background: var(--surface);
    margin-bottom: var(--space-3);
  }

  .notify-head,
  .notify-row {
    display: grid;
    grid-template-columns: 1fr 90px 90px;
    align-items: center;
    gap: var(--space-2);
    padding: 10px var(--space-4);
    border-bottom: 1px solid var(--border);
    font-size: 14px;
  }

  .notify-head {
    font-size: 12px;
    color: var(--muted);
    background: color-mix(in srgb, var(--primary) 4%, white);
  }

  .notify-row:last-child {
    border-bottom: none;
  }

  .notify-row label {
    display: flex;
    justify-content: center;
  }

  .notify-title {
    min-width: 0;
  }
</style>
