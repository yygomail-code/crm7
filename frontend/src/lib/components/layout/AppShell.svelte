<script lang="ts">
  import { onMount } from 'svelte';
  import type { Snippet } from 'svelte';
  import { chatUnread } from '../../api/chat';
  import { unreadCount } from '../../api/notifications';
  import { loadAvatarUrl } from '../../api/profile';
  import { config } from '../../config';
  import { auth } from '../../stores/auth.svelte';
  import { cart } from '../../stores/cart.svelte';
  import { router } from '../../router.svelte';
  import Button from '../ui/Button.svelte';
  import Logo from '../ui/Logo.svelte';

  interface Props {
    children?: Snippet;
  }

  let { children }: Props = $props();

  let unread = $state(0);
  let chatUnreadCount = $state(0);
  let avatarUrl = $state<string | null>(null);

  const initials = $derived(
    (auth.name || '')
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? '')
      .join('')
  );

  const path = $derived(router.current.path);

  const showClients = $derived(auth.can('clients.view.all') || auth.can('clients.view.own'));

  const showSettings = $derived(auth.can('settings.manage'));

  const showAdmin = $derived(auth.can('users.manage') || auth.can('audit.view'));

  const showReports = $derived(auth.can('reports.view.all') || auth.can('reports.view.own'));

  const showSubstitutions = $derived(auth.can('clients.assign'));

  onMount(() => {
    if (auth.user) {
      void loadAvatarUrl(auth.user.id).then((url) => {
        avatarUrl = url;
      });
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

  function logout(): void {
    void auth.logout();
    router.navigate('/');
  }
</script>

<div class="shell">
  <header>
    <div class="left">
      <a class="brand" href="#/requests">
        <Logo size={26} />
        <span>CRM</span>
      </a>
      <nav class="desktop-nav">
        <a href="#/requests" class:active={path.startsWith('/requests')}>Заявки</a>
        {#if showClients}
          <a href="#/clients" class:active={path === '/clients'}>Клиенты</a>
        {/if}
        <a href="#/stocks" class:active={path === '/stocks'}>Склады</a>
        {#if showReports}
          <a href="#/reports" class:active={path === '/reports'}>Отчёты</a>
        {/if}
        {#if showSubstitutions}
          <a href="#/substitutions" class:active={path === '/substitutions'}>Замещения</a>
        {/if}
        {#if showAdmin}
          <a href="#/admin" class:active={path === '/admin'}>Админ</a>
        {/if}
        {#if showSettings}
          <a href="#/settings" class:active={path === '/settings'}>Настройки</a>
        {/if}
        <a href="#/my-reports" class:active={path === '/my-reports'}>Мои отчёты</a>
        <a href="#/profile" class:active={path === '/profile'}>Профиль</a>
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
        <svg
          viewBox="0 0 24 24"
          width="20"
          height="20"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <circle cx="12" cy="12" r="9" />
          <path d="M12 11v5" />
          <path d="M12 7.6h.01" />
        </svg>
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
        <svg
          viewBox="0 0 24 24"
          width="20"
          height="20"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9.9 9.9 0 0 1-4.2-.9L3 20l1.1-4.2A8.4 8.4 0 0 1 12 3.1a8.4 8.4 0 0 1 9 8.4Z" />
        </svg>
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
        <svg
          viewBox="0 0 24 24"
          width="20"
          height="20"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
          <path d="M13.7 21a2 2 0 0 1-3.4 0" />
        </svg>
        {#if unread > 0}
          <span class="bell-badge">{unread > 99 ? '99+' : unread}</span>
        {/if}
      </button>
      {#if cart.count > 0}
        <button
          type="button"
          class="bell has-new"
          aria-label={`Заявка из склада: ${cart.count} позиций`}
          title="Создать заявку из выбранных позиций"
          onclick={() => router.navigate('/requests/new')}
        >
          <svg
            viewBox="0 0 24 24"
            width="20"
            height="20"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
          >
            <path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L21 7H6" />
            <circle cx="10" cy="20" r="1" />
            <circle cx="18" cy="20" r="1" />
          </svg>
          <span class="bell-badge">{cart.count > 99 ? '99+' : cart.count}</span>
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
          <span>{initials || '•'}</span>
        {/if}
      </button>
      <span class="name">{auth.name || 'Пользователь'}</span>
      <Button variant="ghost" onclick={logout}>Выйти</Button>
    </div>
  </header>

<main>
  {@render children?.()}
</main>

  <nav class="mobile-nav">
    <a href="#/requests" class:active={path.startsWith('/requests')}>
      <span class="icon">≡</span>
      Заявки
    </a>
    <a href="#/stocks" class:active={path === '/stocks'}>
      <span class="icon">▤</span>
      Склады
    </a>
    {#if showClients}
      <a href="#/clients" class:active={path === '/clients'}>
        <span class="icon">☺</span>
        Клиенты
      </a>
    {/if}
    {#if showReports}
      <a href="#/reports" class:active={path === '/reports'}>
        <span class="icon">◫</span>
        Отчёты
      </a>
    {/if}
    {#if showSubstitutions}
      <a href="#/substitutions" class:active={path === '/substitutions'}>
        <span class="icon">⇄</span>
        Замещения
      </a>
    {/if}
    {#if !showReports}
      <a href="#/my-reports" class:active={path === '/my-reports'}>
        <span class="icon">◫</span>
        Отчёты
      </a>
    {/if}
    <a href="#/profile" class:active={path === '/profile'}>
      <span class="icon">⚙</span>
      Профиль
    </a>
  </nav>
</div>

<style>
  .shell {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
  }

  header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-4);
    padding: var(--space-3) var(--space-5);
    background: var(--surface);
    border-bottom: 1px solid var(--border);
  }

  .left {
    display: flex;
    align-items: center;
    gap: var(--space-5);
  }

  .brand {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: inherit;
    font-size: 18px;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-decoration: none;
  }

  nav.desktop-nav {
    display: flex;
    gap: var(--space-4);
  }

  nav a {
    color: var(--muted);
    text-decoration: none;
    font-size: 14px;
    padding: 4px 0;
    border-bottom: 2px solid transparent;
  }

  nav a.active,
  nav a:hover {
    color: var(--text);
    border-bottom-color: var(--primary);
  }

  .user {
    display: flex;
    align-items: center;
    gap: var(--space-3);
  }

  .name {
    font-weight: 500;
  }

  .avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
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

  .avatar:hover {
    border-color: var(--primary);
  }

  .bell {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    padding: 0;
    border: 1px solid transparent;
    border-radius: 50%;
    background: none;
    color: var(--muted);
    cursor: pointer;
  }

  .bell:hover {
    color: var(--text);
    border-color: var(--border);
  }

  .bell.has-new {
    color: var(--primary);
  }

  .bell.current {
    border-color: var(--primary);
    color: var(--primary);
  }

  .bell-badge {
    position: absolute;
    top: 0;
    right: 0;
    min-width: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: var(--primary);
    color: #fff;
    font-size: 11px;
    line-height: 18px;
    text-align: center;
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
      padding: var(--space-3) var(--space-4);
    }

    .desktop-nav {
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

    .icon {
      font-size: 18px;
      line-height: 1;
    }
  }
</style>
