<script lang="ts">
  import type { FullAutoFill } from 'svelte/elements';

  interface Props {
    label?: string;
    type?: string;
    value?: string;
    placeholder?: string;
    error?: string;
    autocomplete?: FullAutoFill;
    name?: string;
  }

  let {
    label = '',
    type = 'text',
    value = $bindable(''),
    placeholder = '',
    error = '',
    autocomplete,
    name = ''
  }: Props = $props();
</script>

<label class="field">
  {#if label}
    <span class="label">{label}</span>
  {/if}
  <input {type} bind:value {placeholder} {autocomplete} {name} class:invalid={Boolean(error)} />
  {#if error}
    <span class="error">{error}</span>
  {/if}
</label>

<style>
  .field {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
  }

  .label {
    font-size: 13px;
    color: var(--muted);
  }

  input {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }

  input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
  }

  input.invalid {
    border-color: var(--danger);
  }

  .error {
    font-size: 12px;
    color: var(--danger);
  }
</style>
