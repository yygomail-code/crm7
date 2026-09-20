<script lang="ts">
  import { ApiError, apiRequest } from '../lib/api/client';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Input from '../lib/components/ui/Input.svelte';

  let email = $state('');
  let sent = $state(false);
  let error = $state('');
  let loading = $state(false);

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    error = '';
    loading = true;

    try {
      await apiRequest('/auth/password/reset-request', {
        method: 'POST',
        body: { email: email.trim() }
      });
      sent = true;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось отправить запрос';
    } finally {
      loading = false;
    }
  }
</script>

<div class="wrap">
  <div class="card">
    <h1>Восстановление пароля</h1>

    {#if sent}
      <p>
        Если такой e-mail зарегистрирован, мы отправили на него письмо со ссылкой для установки
        нового пароля. Ссылка действует 60 минут.
      </p>
      <Button variant="ghost" onclick={() => router.navigate('/')}>Вернуться ко входу</Button>
    {:else}
      <p class="hint">Укажите e-mail, привязанный к вашей учётной записи.</p>

      <form onsubmit={submit}>
        <Input label="E-mail" type="email" bind:value={email} autocomplete="email" />

        {#if error}
          <div class="alert">{error}</div>
        {/if}

        <Button type="submit" loading={loading} disabled={!email.trim()}>
          Отправить ссылку
        </Button>
      </form>

      <button type="button" class="link" onclick={() => router.navigate('/')}>
        Вернуться ко входу
      </button>
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
    color: var(--muted);
    font-size: 14px;
  }

  .alert {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--danger);
    font-size: 13px;
  }

  .link {
    margin-top: var(--space-4);
    padding: 0;
    border: none;
    background: none;
    color: var(--primary);
    cursor: pointer;
    font-size: 14px;
  }
</style>
