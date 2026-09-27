<script lang="ts">
  import type { Snippet } from 'svelte';
  import Button from './Button.svelte';
  import Modal from './Modal.svelte';

  interface Props {
    count?: number;
    title?: string;
    onapply: () => void;
    onreset?: () => void;
    onopen?: () => void;
    children?: Snippet;
  }

  let { count = 0, title = 'Фильтры', onapply, onreset, onopen, children }: Props = $props();

  let open = $state(false);

  function openModal(): void {
    onopen?.();
    open = true;
  }

  function apply(): void {
    open = false;
    onapply();
  }
</script>

<Button variant="ghost" onclick={openModal}>
  Фильтры{#if count > 0}<span class="count">{count}</span>{/if}
</Button>

<Modal {open} {title} onclose={() => (open = false)}>
  <div class="filter-form">
    {@render children?.()}
  </div>

  <div class="modal-actions">
    {#if onreset}
      <Button variant="ghost" onclick={() => onreset?.()}>Сбросить</Button>
    {/if}
    <Button onclick={apply}>Применить</Button>
  </div>
</Modal>

<style>
  .count {
    margin-left: 6px;
    min-width: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: var(--primary);
    color: #fff;
    font-size: 11px;
    line-height: 17px;
    text-align: center;
  }

  .filter-form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-4);
  }
</style>
