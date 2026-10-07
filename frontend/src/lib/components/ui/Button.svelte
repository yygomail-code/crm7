<script lang="ts">
  import type { Snippet } from 'svelte';
  import Spinner from './Spinner.svelte';

  interface Props {
    type?: 'button' | 'submit';
    variant?: 'primary' | 'ghost' | 'text' | 'danger';
    size?: 'sm' | 'md';
    disabled?: boolean;
    loading?: boolean;
    title?: string;
    ariaLabel?: string;
    onclick?: (event: MouseEvent) => void;
    children?: Snippet;
  }

  let {
    type = 'button',
    variant = 'primary',
    size = 'md',
    disabled = false,
    loading = false,
    title,
    ariaLabel,
    onclick,
    children
  }: Props = $props();
</script>

<button
  {type}
  class={variant}
  class:sm={size === 'sm'}
  disabled={disabled || loading}
  {title}
  aria-label={ariaLabel}
  {onclick}
>
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
    height: 32px;
    padding: 0 15px;
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

  button.sm {
    padding: 0 8px;
    font-size: 14px;
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
    border-color: var(--primary-hover);
    color: var(--primary-hover);
  }

  .text {
    background: transparent;
    border-color: transparent;
    color: var(--primary);
  }

  .text:hover:not(:disabled) {
    background: rgba(0, 0, 0, 0.04);
  }

  .danger {
    background: var(--danger);
    color: #fff;
  }
</style>
