<script lang="ts">
  import { onMount } from 'svelte';
  import type { Snippet } from 'svelte';
  import { chatUnread } from '../../api/chat';
  import { unreadCount } from '../../api/notifications';
  import { config } from '../../config';
  import { avatarColor, avatarLetter, avatarName } from '../../avatar';
  import Icon from '../ui/Icon.svelte';
  import { auth } from '../../stores/auth.svelte';
  import { avatars } from '../../stores/avatar.svelte';
  import { appSettings } from '../../stores/app-settings.svelte';
  import { cart } from '../../stores/cart.svelte';
  import { router } from '../../router.svelte';
  import Logo from '../ui/Logo.svelte';

  interface Props {
    children?: Snippet;
  }

  let { children }: Props = $props();

  let unread = $state(0);
  let chatUnreadCount = $state(0);

  const avatarUrl = $derived(avatars.url(auth.user?.id));

  const avatarSeed = $derived(avatarName(auth.user?.name, auth.user?.login ?? 'guest'));
  const letter = $derived(avatarLetter(avatarSeed));
  const avatarBg = $derived(avatarColor(avatarSeed));

  const path = $derived(router.current.path);

  $effect(() => {
    if (!appSettings.loaded) {
      return;
    }

    if (!appSettings.requestsEnabled && path.startsWith('/requests')) {
      router.navigate('/stocks');
    } else if (!appSettings.substitutionsEnabled && path === '/substitutions') {
      router.navigate('/stocks');
    } else if (!appSettings.cartEnabled && path === '/cart') {
      router.navigate('/stocks');
    } else if (!appSettings.warehousesEnabled && path.startsWith('/warehouses')) {
      router.navigate('/stocks');
    } else if (!appSettings.reportsEnabled && (path === '/reports' || path === '/my-reports')) {
      router.navigate('/stocks');
    }
  });

  const showClients = $derived(auth.can('clients.view.all') || auth.can('clients.view.own'));

  const showSettings = $derived(auth.can('settings.manage'));

  const showAdmin = $derived(auth.can('users.manage') || auth.can('audit.view'));

  const canViewReports = $derived(auth.can('reports.view.all') || auth.can('reports.view.own'));

  const showReports = $derived(appSettings.reportsEnabled && canViewReports);

  const showMyReports = $derived(appSettings.reportsEnabled && !canViewReports);

  const showSubstitutions = $derived(auth.can('clients.assign'));

  const showWarehouses = $derived(
    appSettings.warehousesEnabled && (auth.can('stocks.import') || auth.can('stocks.edit'))
  );

  const navCount = $derived(
    3 +
      (showReports ? 1 : 0) +
      (showClients ? 1 : 0) +
      (showSubstitutions ? 1 : 0) +
      (showWarehouses ? 1 : 0) +
      (showAdmin ? 1 : 0) +
      (showSettings ? 1 : 0)
  );

  onMount(() => {
    if (auth.user) {
      void avatars.load(auth.user.id);
    }

    const tick = async (): Promise<void> => {
      try {
        const [notes, chat] = await Promise.all([
          unreadCount().catch(() => ({ unread: 0 })),
          chatUnread().catch(() => ({ unread: 0 }))
        ]);

        unread = notes.unread;
        chatUnreadCount = chat.unread;
      } catch {
        // некритично: обновим на следующем тике
      }
    };

    void tick();
    const timer = setInterval(() => void tick(), config.notificationsPollMs);

    return () => clearInterval(timer);
  });

</script>

<div class="shell">
  <header>
    <div class="left">
      <a class="brand" href="#/requests">
        <Logo size={26} />
        <span>{appSettings.appTitle}</span>
      </a>
      <nav class="desktop-nav" class:many={navCount > 6}>
        <a href="#/stocks" class:active={path === '/stocks'} title="Номенклатура">
          <Icon name="stocks" size={20} />
          <span class="nav-label">Номенклатура</span>
        </a>
        {#if showWarehouses}
          <a href="#/warehouses" class:active={path.startsWith('/warehouses')} title="Склады">
            <Icon name="warehouse" size={20} />
            <span class="nav-label">Склады</span>
          </a>
        {/if}
        {#if appSettings.requestsEnabled}
          <a href="#/requests" class:active={path.startsWith('/requests')} title="Заявки">
            <Icon name="requests" size={20} />
            <span class="nav-label">Заявки</span>
          </a>
        {/if}
        {#if showReports}
          <a href="#/reports" class:active={path === '/reports'} title="Отчёты">
            <Icon name="reports" size={20} />
            <span class="nav-label">Отчёты</span>
          </a>
        {/if}
        {#if showMyReports}
          <a href="#/my-reports" class:active={path === '/my-reports'} title="Мои отчёты">
            <Icon name="my-reports" size={20} />
            <span class="nav-label">Мои отчёты</span>
          </a>
        {/if}
        {#if showClients}
          <a href="#/clients" class:active={path === '/clients'} title="Клиенты">
            <Icon name="clients" size={20} />
            <span class="nav-label">Клиенты</span>
          </a>
        {/if}
        {#if showSubstitutions && appSettings.substitutionsEnabled}
          <a href="#/substitutions" class:active={path === '/substitutions'} title="Замещения">
            <Icon name="substitutions" size={20} />
            <span class="nav-label">Замещения</span>
          </a>
        {/if}
        {#if showAdmin}
          <a href="#/admin" class:active={path === '/admin'} title="Админ">
            <Icon name="admin" size={20} />
            <span class="nav-label">Админ</span>
          </a>
        {/if}
        {#if showSettings}
          <a href="#/settings" class:active={path === '/settings'} title="Настройки">
            <Icon name="settings" size={20} />
            <span class="nav-label">Настройки</span>
          </a>
        {/if}
      </nav>
    </div>
    <div class="user">
      <button
        type="button"
        class="bell"
        class:current={path === '/about'}
        aria-label="О программе"
        title="О программе"
        onclick={() => router.navigate('/about')}
      >
        <Icon name="info" size={20} />
      </button>
      <button
        type="button"
        class="bell"
        class:has-new={chatUnreadCount > 0}
        class:current={path === '/chat'}
        aria-label={chatUnreadCount > 0 ? `Чат: ${chatUnreadCount} новых` : 'Чат'}
        title="Чат"
        onclick={() => router.navigate('/chat')}
      >
        <Icon name="chat" size={20} />
        {#if chatUnreadCount > 0}
          <span class="bell-badge">{chatUnreadCount > 99 ? '99+' : chatUnreadCount}</span>
        {/if}
      </button>
      <button
        type="button"
        class="bell"
        class:has-new={unread > 0}
        class:current={path === '/notifications'}
        aria-label={unread > 0 ? `Уведомления: ${unread} новых` : 'Уведомления'}
        title="Уведомления"
        onclick={() => router.navigate('/notifications')}
      >
        <Icon name="bell" size={20} />
        {#if unread > 0}
          <span class="bell-badge">{unread > 99 ? '99+' : unread}</span>
        {/if}
      </button>
      {#if appSettings.salesEnabled && appSettings.cartEnabled}
        <button
          type="button"
          class="bell"
          class:has-new={cart.count > 0}
          class:current={path === '/cart'}
          aria-label={cart.count > 0 ? `Корзина: ${cart.count} позиций` : 'Корзина'}
          title="Корзина"
          onclick={() => router.navigate('/cart')}
        >
          <Icon name="cart" size={20} />
          {#if cart.count > 0}
            <span class="bell-badge">{cart.count > 99 ? '99+' : cart.count}</span>
          {/if}
        </button>
      {/if}
      <button
        type="button"
        class="avatar"
        title="Профиль"
        aria-label="Профиль"
        onclick={() => router.navigate('/profile')}
      >
        {#if avatarUrl}
          <img src={avatarUrl} alt="" />
        {:else}
          <span style:background={avatarBg}>{letter}</span>
        {/if}
      </button>
      <button type="button" class="name" title="Профиль" onclick={() => router.navigate('/profile')}>
        {auth.name || 'Пользователь'}
      </button>
    </div>
  </header>

  {#if appSettings.demoMode}
    <div class="demo-banner">
      Демо-режим: данные вымышленные, изменения не сохраняются. Смена паролей и управление
      пользователями недоступны.
    </div>
  {/if}

<main>
  {@render children?.()}
</main>

  <nav class="mobile-nav">
    <a href="#/stocks" class:active={path === '/stocks'} title="Номенклатура">
      <Icon name="stocks" size={20} />
      <span class="nav-label">Номенклатура</span>
    </a>
    <a href="#/requests" class:active={path.startsWith('/requests')} title="Заявки">
      <Icon name="requests" size={20} />
      <span class="nav-label">Заявки</span>
    </a>
    {#if showReports}
      <a href="#/reports" class:active={path === '/reports'} title="Отчёты">
        <Icon name="reports" size={20} />
        <span class="nav-label">Отчёты</span>
      </a>
    {/if}
    {#if showMyReports}
      <a href="#/my-reports" class:active={path === '/my-reports'} title="Мои отчёты">
        <Icon name="my-reports" size={20} />
        <span class="nav-label">Отчёты</span>
      </a>
    {/if}
    {#if showClients}
      <a href="#/clients" class:active={path === '/clients'} title="Клиенты">
        <Icon name="clients" size={20} />
        <span class="nav-label">Клиенты</span>
      </a>
    {/if}
    {#if showSubstitutions}
      <a href="#/substitutions" class:active={path === '/substitutions'} title="Замещения">
        <Icon name="substitutions" size={20} />
        <span class="nav-label">Замещения</span>
      </a>
    {/if}
  </nav>
</div>

<style>
  .shell {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
  }

  .demo-banner {
    padding: var(--space-2) var(--space-5);
    background: var(--warning-bg, #fff4d6);
    color: var(--warning, #8a6d00);
    border-bottom: 1px solid var(--border);
    font-size: 13px;
    text-align: center;
  }

  header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-4);
    height: 64px;
    padding: 0 var(--space-5);
    background: var(--surface);
    border-bottom: 1px solid var(--border-secondary);
  }

  .left {
    display: flex;
    align-items: center;
    gap: var(--space-5);
    height: 100%;
  }

  .brand {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    color: var(--text);
    font-size: 18px;
    font-weight: 600;
    text-decoration: none;
  }

  nav.desktop-nav {
    display: flex;
    align-items: stretch;
    gap: 0;
    height: 100%;
  }

  nav a {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    color: var(--muted);
    text-decoration: none;
    font-size: 14px;
    border-bottom: 2px solid transparent;
    white-space: nowrap;
    transition: color 0.15s ease, border-color 0.15s ease;
  }

  nav a.active,
  nav a:hover {
    color: var(--primary);
    border-bottom-color: var(--primary);
  }

  .desktop-nav a {
    height: 100%;
    padding: 0 var(--space-4);
  }

  @media (max-width: 1150px) {
    .desktop-nav .nav-label {
      display: none;
    }
  }

  @media (max-width: 1520px) {
    .desktop-nav.many .nav-label {
      display: none;
    }
  }

  .user {
    display: flex;
    align-items: center;
    gap: var(--space-2);
  }

  .name {
    padding: 0;
    border: none;
    background: none;
    color: inherit;
    font: inherit;
    font-weight: 500;
    cursor: pointer;
  }

  .name:hover {
    color: var(--primary);
  }

  .avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: color-mix(in srgb, var(--primary) 10%, white);
    color: var(--primary);
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    overflow: hidden;
  }

  .avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .avatar span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    color: #fff;
  }

  .avatar:hover {
    border-color: var(--primary);
  }

  .bell {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    padding: 0;
    border: none;
    border-radius: var(--radius-sm);
    background: none;
    color: var(--muted);
    cursor: pointer;
    transition: color 0.15s ease, background-color 0.15s ease;
  }

  .bell:hover {
    color: var(--primary);
    background: var(--fill-tertiary);
  }

  .bell.has-new {
    color: var(--primary);
  }

  .bell.current {
    color: var(--primary);
    background: var(--primary-bg);
  }

  .bell-badge {
    position: absolute;
    top: 2px;
    right: 2px;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 10px;
    background: var(--danger);
    color: #fff;
    font-size: 12px;
    line-height: 20px;
    text-align: center;
    box-shadow: 0 0 0 1px var(--surface);
  }

  main {
    flex: 1;
    width: 100%;
    max-width: 1280px;
    margin: 0 auto;
    padding: var(--space-5);
  }

  .mobile-nav {
    display: none;
  }

  @media (max-width: 720px) {
    header {
      padding: 0 var(--space-4);
    }

    nav.desktop-nav {
      display: none;
    }

    .name {
      display: none;
    }

    main {
      padding: var(--space-4);
      padding-bottom: 84px;
    }

    .mobile-nav {
      position: fixed;
      left: 0;
      right: 0;
      bottom: 0;
      display: flex;
      justify-content: space-around;
      background: var(--surface);
      border-top: 1px solid var(--border);
      padding: 6px 0 calc(6px + env(safe-area-inset-bottom));
      z-index: 10;
    }

    .mobile-nav a {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 2px;
      font-size: 10px;
      border-bottom: none;
      padding: 4px 6px;
      white-space: nowrap;
    }

    .mobile-nav a.active {
      color: var(--primary);
    }

  @media (max-width: 380px) {
    .mobile-nav .nav-label {
      display: none;
    }
  }
  }
</style>
