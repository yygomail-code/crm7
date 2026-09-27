<script lang="ts">
  import type { Snippet } from 'svelte';

  interface Props {
    open: boolean;
    title: string;
    wide?: boolean;
    onclose: () => void;
    children?: Snippet;
  }

  let { open, title, wide = false, onclose, children }: Props = $props();

  function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      onclose();
    }
  }
</script>

<svelte:window onkeydown={onKeydown} />

{#if open}
  <div class="overlay">
    <button type="button" class="backdrop" aria-label="Закрыть окно" onclick={onclose}></button>
    <div class="dialog" class:wide role="dialog" aria-modal="true" aria-label={title}>
      <div class="dialog-head">
        <h2>{title}</h2>
        <button type="button" class="close" aria-label="Закрыть" onclick={onclose}>×</button>
      </div>
      <div class="dialog-body">
        {@render children?.()}
      </div>
    </div>
  </div>
{/if}

<style>
  .overlay {
    position: fixed;
    inset: 0;
    z-index: 50;
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    place-items: center;
    padding: var(--space-4);
  }

  .backdrop {
    position: absolute;
    inset: 0;
    border: none;
    padding: 0;
    background: rgba(15, 23, 42, 0.45);
    cursor: default;
  }

  .dialog.wide {
    width: min(780px, 100%);
  }

  .dialog {
    position: relative;
    display: flex;
    flex-direction: column;
    width: min(560px, 100%);
    min-width: 0;
    max-height: min(82vh, 760px);
    padding: var(--space-4);
    background: var(--surface);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
  }

  .dialog-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    margin-bottom: var(--space-3);
  }

  h2 {
    margin: 0;
    font-size: 16px;
  }

  .close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: none;
    color: var(--muted);
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
  }

  .close:hover {
    color: var(--text);
    background: var(--bg);
  }

  .dialog-body {
    overflow: auto;
  }
</style>
