<script lang="ts">
  import type { Snippet } from 'svelte';
  import Button from './Button.svelte';
  import Icon from './Icon.svelte';
  import Modal from './Modal.svelte';

  interface Props {
    count?: number;
    title?: string;
    icon?: boolean;
    onapply: () => void;
    onreset?: () => void;
    onopen?: () => void;
    children?: Snippet;
  }

  let { count = 0, title = 'Фильтры', icon = false, onapply, onreset, onopen, children }: Props =
    $props();

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

{#if icon}
  <button
    type="button"
    class="icon-trigger"
    class:active={count > 0}
    title="Фильтры"
    aria-label="Фильтры"
    onclick={openModal}
  >
    <Icon name="filter" size={16} />
    {#if count > 0}<span class="badge">{count}</span>{/if}
  </button>
{:else}
  <Button variant="ghost" onclick={openModal}>
    Фильтры{#if count > 0}<span class="count">{count}</span>{/if}
  </Button>
{/if}

<Modal {open} {title} onclose={() => (open = false)}>
  <div class="filter-form">
    {@render children?.()}
  </div>

  <div class="modal-actions">
    {#if onreset}
      <Button variant="ghost" onclick={() => onreset?.()}>Сбросить</Button>
    {/if}
    <Button variant="ghost" onclick={() => (open = false)}>Отмена</Button>
    <Button onclick={apply}>Применить</Button>
  </div>
</Modal>

<style>
  .icon-trigger {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
    transition: color 0.15s ease, border-color 0.15s ease;
  }

  .icon-trigger:hover {
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .icon-trigger.active {
    border-color: var(--primary);
    color: var(--primary);
  }

  .badge {
    position: absolute;
    top: -6px;
    right: -6px;
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    border-radius: 999px;
    background: var(--primary);
    color: #fff;
    font-size: 10px;
    line-height: 16px;
    text-align: center;
  }

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
