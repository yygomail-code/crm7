<script lang="ts">
  import { getHistory } from '../../search-history';
  import Icon from './Icon.svelte';

  interface Props {
    value?: string;
    placeholder?: string;
    historyKey?: string;
    icon?: boolean;
    onclear?: () => void;
    onpick?: () => void;
  }

  let {
    value = $bindable(''),
    placeholder = '',
    historyKey = '',
    icon = false,
    onclear,
    onpick
  }: Props = $props();

  let history = $state<string[]>([]);
  let showHistory = $state(false);
  let activeIndex = $state(-1);

  function openHistory(): void {
    if (historyKey === '') {
      return;
    }

    history = getHistory(historyKey);
    activeIndex = -1;
    showHistory = value === '' && history.length > 0;
  }

  function pick(item: string): void {
    value = item;
    showHistory = false;
    activeIndex = -1;
    onpick?.();
  }

  function moveHistory(step: number): void {
    if (history.length === 0) {
      return;
    }

    activeIndex = Math.min(Math.max(activeIndex + step, 0), history.length - 1);
  }
</script>

<span class="wrap" class:with-icon={icon}>
  {#if icon}
    <span class="lead"><Icon name="search" size={14} /></span>
  {/if}
  <input
    type="search"
    bind:value
    {placeholder}
    onfocus={openHistory}
    oninput={() => {
      showHistory = false;
      activeIndex = -1;
    }}
    onblur={() => {
      showHistory = false;
      activeIndex = -1;
    }}
    onkeydown={(event) => {
      if (event.key === 'Escape') {
        showHistory = false;
        activeIndex = -1;
        return;
      }

      if (!showHistory) {
        return;
      }

      if (event.key === 'ArrowDown') {
        event.preventDefault();
        moveHistory(1);
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        moveHistory(-1);
      } else if (event.key === 'Enter' && activeIndex >= 0) {
        event.preventDefault();
        pick(history[activeIndex]);
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
      {#each history as item, index (item)}
        <button
          type="button"
          class="history-item"
          class:active={index === activeIndex}
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

  .wrap.with-icon input {
    padding-left: 30px;
  }

  .lead {
    position: absolute;
    left: 10px;
    top: 50%;
    display: inline-flex;
    transform: translateY(-50%);
    color: var(--muted);
    pointer-events: none;
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

  .history-item.active {
    background: var(--bg);
  }
</style>
