<script lang="ts">
  import type { Snippet } from 'svelte';

  interface Props {
    label?: string;
    value?: string | number;
    disabled?: boolean;
    name?: string;
    id?: string;
    ariaLabel?: string;
    ariaInvalid?: boolean;
    ariaDescribedby?: string;
    children?: Snippet;
  }

  let {
    label = '',
    value = $bindable(''),
    disabled = false,
    name = '',
    id,
    ariaLabel,
    ariaInvalid,
    ariaDescribedby,
    children
  }: Props = $props();
</script>

<label class="field">
  {#if label}
    <span class="label">{label}</span>
  {/if}
  <select
    bind:value
    {disabled}
    {name}
    {id}
    aria-label={ariaLabel}
    aria-invalid={ariaInvalid ? 'true' : undefined}
    aria-describedby={ariaDescribedby}
  >
    {@render children?.()}
  </select>
</label>

<style>
  .field {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
  }

  .label {
    font-size: 14px;
    color: var(--text-description);
  }

  select {
    width: 100%;
    height: 32px;
    padding: 0 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: 14px;
    outline: none;
  }

  select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--focus-ring);
  }

  select:disabled {
    background: var(--fill-tertiary);
    color: var(--text-description);
    cursor: not-allowed;
  }
</style>
