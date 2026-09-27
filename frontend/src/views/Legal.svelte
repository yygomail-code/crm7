<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { getLegalDocument, type LegalDocument } from '../lib/api/legal';
  import { router } from '../lib/router.svelte';
  import { formatDate } from '../lib/format';
  import Button from '../lib/components/ui/Button.svelte';
  import Spinner from '../lib/components/ui/Spinner.svelte';

  interface Props {
    code: string;
  }

  let { code }: Props = $props();

  let document = $state<LegalDocument | null>(null);
  let loading = $state(true);
  let error = $state('');

  onMount(() => {
    void load();
  });

  async function load(): Promise<void> {
    loading = true;
    error = '';

    try {
      document = await getLegalDocument(code);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось загрузить документ';
    } finally {
      loading = false;
    }
  }

  function back(): void {
    if (window.history.length > 1) {
      window.history.back();
    } else {
      router.navigate('/');
    }
  }
</script>

<div class="wrap">
  <div class="card">
    <div class="back-row">
      <Button variant="ghost" onclick={back}>← Назад</Button>
    </div>

    {#if loading}
      <div class="center"><Spinner size={24} /></div>
    {:else if error}
      <p class="error">{error}</p>
    {:else if document}
      <h1>{document.title}</h1>
      <p class="updated">Обновлено: {formatDate(document.updated_at)}</p>
      <div class="body">{document.body}</div>
    {/if}
  </div>
</div>

<style>
  .wrap {
    min-height: 100vh;
    display: grid;
    place-items: start center;
    padding: var(--space-4);
  }

  .card {
    width: 100%;
    max-width: 760px;
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-6);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
  }

  .back-row {
    display: flex;
    align-self: flex-start;
  }

  h1 {
    margin: 0;
    font-size: 22px;
  }

  .updated {
    margin: 0;
    font-size: 12px;
    color: var(--muted);
  }

  .body {
    white-space: pre-wrap;
    font-size: 14px;
    line-height: 1.55;
  }

  .error {
    color: var(--danger);
  }

  .center {
    display: grid;
    place-items: center;
    padding: var(--space-5);
  }
</style>
