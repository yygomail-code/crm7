<script lang="ts">
  import { ApiError, apiRequest } from '../lib/api/client';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Input from '../lib/components/ui/Input.svelte';

  const token = $derived(router.current.query.get('token') ?? '');

  let next = $state('');
  let confirmation = $state('');
  let error = $state('');
  let done = $state(false);
  let loading = $state(false);

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    error = '';

    if (next !== confirmation) {
      error = 'Пароли не совпадают';
      return;
    }

    loading = true;

    try {
      await apiRequest('/auth/password/reset', {
        method: 'POST',
        body: { token, new_password: next }
      });
      done = true;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось сбросить пароль';
    } finally {
      loading = false;
    }
  }
</script>

<div class="wrap">
  <div class="card">
    <h1>Новый пароль</h1>

    {#if !token}
      <div class="alert">Ссылка некорректна: отсутствует токен.</div>
      <Button variant="ghost" onclick={() => router.navigate('/')}>Вернуться ко входу</Button>
    {:else if done}
      <p>Пароль обновлён. Теперь можно войти с новым паролем.</p>
      <Button onclick={() => router.navigate('/')}>Войти</Button>
    {:else}
      <form onsubmit={submit}>
        <Input
          label="Новый пароль"
          type="password"
          bind:value={next}
          autocomplete="new-password"
        />
        <Input
          label="Повторите пароль"
          type="password"
          bind:value={confirmation}
          autocomplete="new-password"
        />

        {#if error}
          <div class="alert">{error}</div>
        {/if}

        <Button type="submit" loading={loading} disabled={!next || !confirmation}>
          Сохранить пароль
        </Button>
      </form>

      <p class="hint">Пароль должен быть не короче 10 символов и содержать буквы и цифры.</p>
    {/if}
  </div>
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
    max-width: 420px;
    padding: var(--space-6);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
  }

  h1 {
    font-size: 20px;
  }

  form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    margin-top: var(--space-3);
  }

  .hint {
    margin-top: var(--space-3);
    color: var(--muted);
    font-size: 13px;
  }

  .alert {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--danger);
    font-size: 13px;
    margin-bottom: var(--space-3);
  }
</style>
