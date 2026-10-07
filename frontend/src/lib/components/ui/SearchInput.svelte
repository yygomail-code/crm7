<script lang="ts">
  import { getHistory } from '../../search-history';
  import Icon from './Icon.svelte';

  interface Props {
    value?: string;
    placeholder?: string;
    historyKey?: string;
    onclear?: () => void;
    onpick?: () => void;
  }

  let {
    value = $bindable(''),
    placeholder = '',
    historyKey = '',
    onclear,
    onpick
  }: Props = $props();

  let history = $state<string[]>([]);
  let showHistory = $state(false);

  function openHistory(): void {
    if (historyKey === '') {
      return;
    }

    history = getHistory(historyKey);
    showHistory = value === '' && history.length > 0;
  }

  function pick(item: string): void {
    value = item;
    showHistory = false;
    onpick?.();
  }
</script>

<span class="wrap">
  <input
    type="search"
    bind:value
    {placeholder}
    onfocus={openHistory}
    oninput={() => (showHistory = false)}
    onblur={() => (showHistory = false)}
    onkeydown={(event) => {
      if (event.key === 'Escape') {
        showHistory = false;
      }
    }}
  />

  {#if value !== ''}
    <button
      type="button"
      class="clear"
      title="Очистить"
      aria-label="Очистить поле поиска"
      onclick={() => {
        value = '';
        onclear?.();
      }}
    >
      <Icon name="close-circle" size={14} />
    </button>
  {/if}

  {#if showHistory}
    <div class="history">
      {#each history as item (item)}
        <button
          type="button"
          class="history-item"
          title={item}
          onmousedown={(event) => {
            event.preventDefault();
            pick(item);
          }}
        >
          {item}
        </button>
      {/each}
    </div>
  {/if}
</span>

<style>
  .wrap {
    position: relative;
    display: flex;
    flex: 1 1 180px;
    min-width: 0;
  }

  input {
    flex: 1;
    min-width: 0;
    height: 32px;
    padding: 0 34px 0 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }

  input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(5, 145, 255, 0.1);
  }

  input::-webkit-search-cancel-button {
    display: none;
  }

  .clear {
    position: absolute;
    right: 6px;
    top: 50%;
    transform: translateY(-50%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: none;
    color: var(--muted);
    cursor: pointer;
  }

  .clear:hover {
    color: var(--text);
    background: var(--bg);
  }

  .history {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    z-index: 20;
    display: flex;
    flex-direction: column;
    padding: 4px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-md);
  }

  .history-item {
    padding: 7px 8px;
    border: none;
    border-radius: var(--radius-sm);
    background: none;
    color: var(--text);
    font: inherit;
    font-size: 14px;
    text-align: left;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    cursor: pointer;
  }

  .history-item:hover {
    background: var(--bg);
  }
</style>
