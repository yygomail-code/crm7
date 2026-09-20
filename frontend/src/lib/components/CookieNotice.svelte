<script lang="ts">
  import { router } from '../router.svelte';

  const STORAGE_KEY = 'crm.cookie.accepted';

  let visible = $state(false);

  $effect(() => {
    try {
      visible = localStorage.getItem(STORAGE_KEY) !== '1';
    } catch {
      visible = true;
    }
  });

  function accept(): void {
    try {
      localStorage.setItem(STORAGE_KEY, '1');
    } catch {
      // приватный режим — просто скрываем
    }

    visible = false;
  }
</script>

{#if visible}
  <div class="cookie" role="dialog" aria-label="Использование cookie">
    <p>
      Мы используем cookie, чтобы сохранять сессию и настройки. Продолжая работу, вы соглашаетесь
      с <button type="button" class="link" onclick={() => router.navigate('/legal/privacy')}>политикой обработки персональных данных</button>
      и <button type="button" class="link" onclick={() => router.navigate('/legal/terms')}>пользовательским соглашением</button>.
    </p>
    <button type="button" class="accept" onclick={accept}>Принять</button>
  </div>
{/if}

<style>
  .cookie {
    position: fixed;
    left: var(--space-4);
    right: var(--space-4);
    bottom: calc(var(--space-4) + env(safe-area-inset-bottom));
    z-index: 40;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-4);
    max-width: 760px;
    margin: 0 auto;
    padding: var(--space-3) var(--space-4);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
    font-size: 13px;
  }

  .cookie p {
    margin: 0;
  }

  .link {
    border: none;
    background: none;
    padding: 0;
    color: var(--primary);
    cursor: pointer;
    font: inherit;
    text-decoration: underline;
  }

  .accept {
    flex: 0 0 auto;
    padding: 8px 18px;
    border: none;
    border-radius: var(--radius-sm);
    background: var(--primary);
    color: #fff;
    cursor: pointer;
    font: inherit;
    font-size: 13px;
  }

  @media (max-width: 720px) {
    .cookie {
      flex-direction: column;
      align-items: stretch;
      bottom: 84px;
    }
  }
</style>
