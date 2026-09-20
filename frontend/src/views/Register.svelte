<script lang="ts">
  import { ApiError } from '../lib/api/client';
  import { registerClient } from '../lib/api/clients';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Input from '../lib/components/ui/Input.svelte';

  let name = $state('');
  let company = $state('');
  let inn = $state('');
  let phone = $state('');
  let email = $state('');
  let password = $state('');
  let passwordConfirm = $state('');
  let consent = $state(false);
  let error = $state('');
  let loading = $state(false);
  let sent = $state(false);

  const ready = $derived(
    name.trim().length >= 3 && email.trim() !== '' && password !== '' && passwordConfirm !== ''
  );

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    error = '';
    loading = true;

    try {
      await registerClient({
        name: name.trim(),
        company: company.trim(),
        inn: inn.trim(),
        phone: phone.trim(),
        email: email.trim(),
        password,
        password_confirm: passwordConfirm,
        consent
      });

      sent = true;
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось отправить заявку';
    } finally {
      loading = false;
    }
  }
</script>

<div class="wrap">
  {#if sent}
    <div class="card">
      <h1>Заявка отправлена</h1>
      <p class="hint">
        Менеджер проверит данные и подтвердит регистрацию. После подтверждения вы сможете войти,
        используя e-mail и пароль.
      </p>
      <Button onclick={() => router.navigate('/')}>На страницу входа</Button>
    </div>
  {:else}
    <form class="card" onsubmit={submit}>
      <h1>Регистрация</h1>
      <p class="hint">Заполните данные — менеджер подтвердит регистрацию</p>

      <Input label="ФИО" bind:value={name} name="name" autocomplete="name" />
      <Input label="Компания" bind:value={company} name="company" autocomplete="organization" />
      <Input label="ИНН" bind:value={inn} name="inn" autocomplete="off" placeholder="10 или 12 цифр" />
      <Input label="Телефон" bind:value={phone} name="phone" autocomplete="tel" />
      <Input label="E-mail" type="email" bind:value={email} name="email" autocomplete="email" />
      <Input
        label="Пароль"
        type="password"
        bind:value={password}
        name="new-password"
        autocomplete="new-password"
      />
      <Input
        label="Пароль ещё раз"
        type="password"
        bind:value={passwordConfirm}
        name="confirm-password"
        autocomplete="new-password"
      />

      <p class="rules">Пароль: не менее 10 символов, буквы и цифры.</p>

      <label class="consent">
        <input type="checkbox" bind:checked={consent} />
        <span>
          Согласен с
          <button type="button" class="link" onclick={() => router.navigate('/legal/privacy')}>политикой обработки персональных данных</button>
          и
          <button type="button" class="link" onclick={() => router.navigate('/legal/terms')}>пользовательским соглашением</button>,
          даю
          <button type="button" class="link" onclick={() => router.navigate('/legal/consent')}>согласие на обработку персональных данных</button>
        </span>
      </label>

      {#if error}
        <div class="error">{error}</div>
      {/if}

      <Button type="submit" loading={loading} disabled={!ready || !consent}>Отправить заявку</Button>

      <button type="button" class="link" onclick={() => router.navigate('/')}>
        Уже есть аккаунт? Войти
      </button>
    </form>
  {/if}
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
    font-size: 22px;
    text-align: center;
  }

  .hint {
    margin: 0 0 var(--space-2);
    text-align: center;
    color: var(--muted);
    font-size: 14px;
  }

  .rules {
    margin: 0;
    font-size: 12px;
    color: var(--muted);
  }

  .consent {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 12px;
    color: var(--muted);
    line-height: 1.5;
  }

  .consent input {
    margin-top: 2px;
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
</style>
