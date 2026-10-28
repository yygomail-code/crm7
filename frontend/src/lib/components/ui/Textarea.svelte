<script lang="ts">
  interface Props {
    label?: string;
    value?: string;
    rows?: number;
    placeholder?: string;
    disabled?: boolean;
    maxlength?: number;
    id?: string;
    ariaLabel?: string;
    ariaInvalid?: boolean;
    ariaDescribedby?: string;
  }

  let {
    label = '',
    value = $bindable(''),
    rows = 3,
    placeholder = '',
    disabled = false,
    maxlength,
    id,
    ariaLabel,
    ariaInvalid,
    ariaDescribedby
  }: Props = $props();
</script>

<label class="field">
  {#if label}
    <span class="label">{label}</span>
  {/if}
  <textarea
    bind:value
    {rows}
    {placeholder}
    {disabled}
    {maxlength}
    {id}
    aria-label={ariaLabel}
    aria-invalid={ariaInvalid ? 'true' : undefined}
    aria-describedby={ariaDescribedby}
  ></textarea>
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

  textarea {
    width: 100%;
    padding: 6px 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: 14px;
    outline: none;
    resize: vertical;
  }

  textarea:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--focus-ring);
  }

  textarea:disabled {
    background: var(--fill-tertiary);
    color: var(--text-description);
    cursor: not-allowed;
  }
</style>
