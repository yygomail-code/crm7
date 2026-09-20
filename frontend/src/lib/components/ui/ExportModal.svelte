<script lang="ts">
  interface Props {
    open: boolean;
    title?: string;
    email?: string;
    onpick: (format: string, byEmail: boolean) => void;
    onclose: () => void;
  }

  let { open, title = 'Экспорт', email = '', onpick, onclose }: Props = $props();

  let sendByEmail = $state(false);

  const formats = [
    { code: 'xlsx', label: 'Excel (XLSX)', hint: 'современный Excel' },
    { code: 'xls', label: 'Excel (XLS)', hint: 'старый Excel' },
    { code: 'csv', label: 'CSV', hint: 'обмен и импорт' },
    { code: 'txt', label: 'TXT', hint: 'простой текст' },
    { code: 'pdf', label: 'PDF', hint: 'печать и отправка' }
  ];

  function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      onclose();
    }
  }
</script>

<svelte:window onkeydown={onKeydown} />

{#if open}
  <div class="overlay">
    <button type="button" class="backdrop" aria-label="Закрыть окно экспорта" onclick={onclose}></button>
    <div class="dialog" role="dialog" aria-modal="true" aria-label={title}>
      <div class="dialog-head">
        <h2>{title}</h2>
        <button type="button" class="close" aria-label="Закрыть" onclick={onclose}>×</button>
      </div>
      <label class="delivery" class:disabled={email === ''}>
        <input type="checkbox" bind:checked={sendByEmail} disabled={email === ''} />
        <span class="delivery-text">
          <span>Отправить на почту вместо скачивания</span>
          <span class="delivery-hint">
            {email !== '' ? email : 'e-mail не указан в профиле'}
          </span>
        </span>
      </label>

      <div class="formats">
        {#each formats as format (format.code)}
          <button type="button" class="format" onclick={() => onpick(format.code, sendByEmail)}>
            <span class="code">{format.code.toUpperCase()}</span>
            <span class="text">
              <span class="label">{format.label}</span>
              <span class="hint">{format.hint}</span>
            </span>
          </button>
        {/each}
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

  .dialog {
    position: relative;
    width: min(420px, 100%);
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

  .delivery {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    margin-bottom: var(--space-3);
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
  }

  .delivery.disabled {
    opacity: 0.6;
    cursor: default;
  }

  .delivery input {
    width: 16px;
    height: 16px;
    accent-color: var(--primary);
  }

  .delivery-text {
    display: flex;
    flex-direction: column;
    font-size: 13px;
  }

  .delivery-hint {
    font-size: 12px;
    color: var(--muted);
  }

  .formats {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .format {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    text-align: left;
    cursor: pointer;
  }

  .format:hover {
    border-color: var(--primary);
    background: color-mix(in srgb, var(--primary) 5%, white);
  }

  .code {
    min-width: 54px;
    padding: 3px 8px;
    border-radius: var(--radius-sm);
    background: color-mix(in srgb, var(--primary) 10%, white);
    color: var(--primary);
    font-size: 11px;
    font-weight: 700;
    text-align: center;
  }

  .text {
    display: flex;
    flex-direction: column;
  }

  .label {
    font-size: 14px;
  }

  .hint {
    font-size: 12px;
    color: var(--muted);
  }
</style>
