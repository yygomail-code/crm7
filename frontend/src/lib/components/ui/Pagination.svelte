<script lang="ts">
  import Button from './Button.svelte';

  interface Props {
    page: number;
    perPage: number;
    total: number;
    loading?: boolean;
    perPageOptions?: number[];
    always?: boolean;
    onchange: (page: number) => void;
    onperpage?: (perPage: number) => void;
  }

  let {
    page,
    perPage,
    total,
    loading = false,
    perPageOptions = [],
    always = false,
    onchange,
    onperpage
  }: Props = $props();

  const pages = $derived(Math.max(1, Math.ceil(total / perPage)));
  const showSelector = $derived(perPageOptions.length > 0 && onperpage !== undefined);
  const minOption = $derived(perPageOptions.length > 0 ? Math.min(...perPageOptions) : perPage);
  const visible = $derived(always || total > perPage || (showSelector && total > minOption));
</script>

{#if visible}
  <div class="pager">
    {#if showSelector}
      <label class="per-page">
        <span>Строк:</span>
        <select
          value={perPage}
          disabled={loading}
          onchange={(event) => onperpage?.(Number(event.currentTarget.value))}
        >
          {#each perPageOptions as option (option)}
            <option value={option}>{option}</option>
          {/each}
        </select>
      </label>
    {:else}
      <span></span>
    {/if}

    <div class="nav">
      <Button variant="ghost" disabled={page <= 1 || loading} onclick={() => onchange(page - 1)}>
        Назад
      </Button>
      <span class="info">Стр. {page} из {pages} · всего {total}</span>
      <Button variant="ghost" disabled={page >= pages || loading} onclick={() => onchange(page + 1)}>
        Вперёд
      </Button>
    </div>
  </div>
{/if}

<style>
  .pager {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    flex-wrap: wrap;
  }

  .nav {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    margin-left: auto;
  }

  .info {
    font-size: 13px;
    color: var(--muted);
  }

  .per-page {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--muted);
  }

  .per-page select {
    padding: 6px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font: inherit;
    font-size: 13px;
    color: var(--text);
  }
</style>
