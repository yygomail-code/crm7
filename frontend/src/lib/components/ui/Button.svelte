<script lang="ts">
  import type { Snippet } from 'svelte';
  import Spinner from './Spinner.svelte';

  interface Props {
    type?: 'button' | 'submit';
    variant?: 'primary' | 'ghost' | 'danger';
    disabled?: boolean;
    loading?: boolean;
    onclick?: (event: MouseEvent) => void;
    children?: Snippet;
  }

  let {
    type = 'button',
    variant = 'primary',
    disabled = false,
    loading = false,
    onclick,
    children
  }: Props = $props();
</script>

<button {type} class={variant} disabled={disabled || loading} {onclick}>
  {#if loading}
    <Spinner size={14} />
  {/if}
  {@render children?.()}
</button>

<style>
  button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    padding: 9px 16px;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    font-size: 14px;
    font-weight: 500;
    white-space: nowrap;
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease;
  }

  button:disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }

  .primary {
    background: var(--primary);
    color: #fff;
  }

  .primary:hover:not(:disabled) {
    background: var(--primary-hover);
  }

  .ghost {
    background: transparent;
    border-color: var(--border);
    color: var(--text);
  }

  .ghost:hover:not(:disabled) {
    background: rgba(23, 25, 28, 0.04);
  }

  .danger {
    background: var(--danger);
    color: #fff;
  }
</style>
