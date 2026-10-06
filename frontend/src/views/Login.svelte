<script lang="ts">
  import { onMount } from 'svelte';
  import { auth } from '../lib/stores/auth.svelte';
  import { router } from '../lib/router.svelte';
  import { apiRequest } from '../lib/api/client';
  import Button from '../lib/components/ui/Button.svelte';
  import Input from '../lib/components/ui/Input.svelte';

  interface DemoAccount {
    login: string;
    role: string;
    password: string;
  }

  let login = $state('');
  let password = $state('');
  let error = $state('');
  let loading = $state(false);
  let demoMode = $state(false);
  let demoAccounts = $state<DemoAccount[]>([]);

  onMount(async () => {
    try {
      const data = await apiRequest<{ demo_mode: boolean; demo_accounts: DemoAccount[] }>(
        '/settings/public'
      );
      demoMode = data.demo_mode === true;
      demoAccounts = Array.isArray(data.demo_accounts) ? data.demo_accounts : [];
    } catch {
      demoMode = false;
      demoAccounts = [];
    }
  });

  function useDemo(account: DemoAccount): void {
    login = account.login;
    password = account.password;
    error = '';
  }

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    error = '';
    loading = true;

    try {
      await auth.login(login.trim(), password);
      router.navigate('/requests');
    } catch (cause) {
      error = cause instanceof Error ? cause.message : 'Ошибка входа';
    } finally {
      loading = false;
    }
  }
</script>

<div class="wrap">
  <form class="card" onsubmit={submit}>
    <h1>CRM7</h1>
    <p class="hint">Вход в систему</p>

    <Input label="Логин" bind:value={login} autocomplete="username" name="login" />
    <Input
      label="Пароль"
      type="password"
      bind:value={password}
      autocomplete="current-password"
      name="password"
    />

    {#if error}
      <div class="error">{error}</div>
    {/if}

    <Button type="submit" loading={loading} disabled={!login.trim() || !password}>
      Войти
    </Button>

    {#if demoMode}
      {#if demoAccounts.length > 0}
        <div class="demo-accounts">
          <p class="hint">Демо-доступ: выберите роль — логин и пароль подставятся.</p>
          {#each demoAccounts as account (account.login)}
            <button type="button" class="demo-account" onclick={() => useDemo(account)}>
              <span class="demo-role">{account.role}</span>
              <span class="demo-cred">{account.login} / {account.password}</span>
            </button>
          {/each}
        </div>
      {:else}
        <p class="hint">Демо-режим: вход по демонстрационным учётным записям</p>
      {/if}
    {:else}
      <button type="button" class="link" onclick={() => router.navigate('/forgot')}>
        Забыли пароль?
      </button>

      <button type="button" class="link" onclick={() => router.navigate('/register')}>
        Нет аккаунта? Зарегистрироваться
      </button>
    {/if}
  </form>
</div>

<style>
  .wrap {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: var(--space-4);
  }

  .card {
    width: 100%;
    max-width: 380px;
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-6);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
  }

  h1 {
    margin: 0;
    font-size: 24px;
    text-align: center;
  }

  .hint {
    margin: 0 0 var(--space-2);
    text-align: center;
    color: var(--muted);
    font-size: 14px;
  }

  .error {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--danger);
    font-size: 13px;
  }

  .link {
    padding: 0;
    border: none;
    background: none;
    color: var(--primary);
    cursor: pointer;
    font-size: 13px;
    text-align: center;
  }

  .demo-accounts {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-top: var(--space-2);
  }

  .demo-account {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    cursor: pointer;
    font-size: 13px;
    text-align: left;
  }

  .demo-account:hover {
    border-color: var(--primary);
    background: var(--bg);
  }

  .demo-role {
    font-weight: 600;
  }

  .demo-cred {
    color: var(--muted);
    font-family: ui-monospace, monospace;
    font-size: 12px;
  }
</style>
