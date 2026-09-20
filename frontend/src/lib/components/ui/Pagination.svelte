<script lang="ts">
  import Button from './Button.svelte';

  interface Props {
    page: number;
    perPage: number;
    total: number;
    loading?: boolean;
    onchange: (page: number) => void;
  }

  let { page, perPage, total, loading = false, onchange }: Props = $props();

  const pages = $derived(Math.max(1, Math.ceil(total / perPage)));
</script>

{#if total > perPage}
  <div class="pager">
    <Button variant="ghost" disabled={page <= 1 || loading} onclick={() => onchange(page - 1)}>
      Назад
    </Button>
    <span class="info">Стр. {page} из {pages} · всего {total}</span>
    <Button variant="ghost" disabled={page >= pages || loading} onclick={() => onchange(page + 1)}>
      Вперёд
    </Button>
  </div>
{/if}

<style>
  .pager {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-3);
  }

  .info {
    font-size: 13px;
    color: var(--muted);
  }
</style>
