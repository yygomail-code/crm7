<script lang="ts">
  import Modal from './Modal.svelte';
  import Button from './Button.svelte';

  interface Props {
    open: boolean;
    title?: string;
    email?: string;
    emailAllowed?: boolean;
    onpick: (format: string, byEmail: boolean) => void;
    onclose: () => void;
  }

  let { open, title = 'Экспорт', email = '', emailAllowed = true, onpick, onclose }: Props = $props();

  let selectedFormat = $state('xlsx');
  let sendByEmail = $state(false);

  const canEmail = $derived(emailAllowed && email !== '');

  const formats = [
    { code: 'xlsx', label: 'Excel (XLSX)', hint: 'современный Excel' },
    { code: 'xls', label: 'Excel (XLS)', hint: 'старый Excel' },
    { code: 'csv', label: 'CSV', hint: 'обмен и импорт' },
    { code: 'txt', label: 'TXT', hint: 'простой текст' },
    { code: 'pdf', label: 'PDF', hint: 'печать и отправка' }
  ];

  $effect(() => {
    if (open) {
      selectedFormat = 'xlsx';
      sendByEmail = false;
    }
  });

  $effect(() => {
    if (!canEmail) {
      sendByEmail = false;
    }
  });

  function submit(): void {
    onpick(selectedFormat, sendByEmail);
  }
</script>

<Modal {open} {title} {onclose}>
  <div class="field">
    <p class="field-label">Формат</p>
    <div class="radio-group">
      {#each formats as format (format.code)}
        <label class="radio-option">
          <input type="radio" name="export-format" value={format.code} bind:group={selectedFormat} />
          <span class="code">{format.code.toUpperCase()}</span>
          <span class="text">
            <span class="label">{format.label}</span>
            <span class="hint">{format.hint}</span>
          </span>
        </label>
      {/each}
    </div>
  </div>

  <div class="field">
    <p class="field-label">Способ получения</p>
    <div class="radio-group">
      <label class="radio-option">
        <input type="radio" name="export-delivery" value={false} bind:group={sendByEmail} />
        <span class="text">
          <span class="label">Скачать файл</span>
        </span>
      </label>
      <label class="radio-option" class:disabled={!canEmail}>
        <input
          type="radio"
          name="export-delivery"
          value={true}
          bind:group={sendByEmail}
          disabled={!canEmail}
        />
        <span class="text">
          <span class="label">Отправить на почту</span>
          <span class="hint">
            {canEmail ? 'Адрес можно посмотреть в профиле' : 'E-mail не указан в профиле'}
          </span>
        </span>
      </label>
    </div>
  </div>

  <div class="modal-actions">
    <Button variant="ghost" onclick={onclose}>Отмена</Button>
    <Button onclick={submit}>Экспортировать</Button>
  </div>
</Modal>

<style>
  .field {
    margin-bottom: var(--space-4);
  }

  .field:last-of-type {
    margin-bottom: 0;
  }

  .field-label {
    margin: 0 0 var(--space-2);
    font-size: 14px;
    color: var(--text);
  }

  .radio-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .radio-option {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 14px;
    cursor: pointer;
  }

  .radio-option.disabled {
    opacity: 0.6;
    cursor: default;
  }

  .radio-option input[type='radio'] {
    width: 16px;
    height: 16px;
    margin: 0;
    accent-color: var(--primary);
    cursor: pointer;
  }

  .radio-option.disabled input {
    cursor: default;
  }

  .code {
    min-width: 54px;
    padding: 3px 8px;
    border: 1px solid var(--primary-border);
    border-radius: 4px;
    background: var(--primary-bg);
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
    color: var(--text-description);
  }

  .modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2);
    margin-top: var(--space-3);
  }
</style>
