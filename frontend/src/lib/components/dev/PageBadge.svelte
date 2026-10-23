<script lang="ts">
  interface Props {
    id: string;
    task?: string;
  }

  let { id, task = '' }: Props = $props();

  let copied = $state(false);
  let timer: ReturnType<typeof setTimeout> | undefined;

  const label = $derived(task ? `${id} — ${task}` : id);

  async function copy(): Promise<void> {
    try {
      await navigator.clipboard.writeText(label);
    } catch {
      return;
    }

    copied = true;
    clearTimeout(timer);
    timer = setTimeout(() => {
      copied = false;
    }, 1200);
  }

  $effect(() => () => clearTimeout(timer));
</script>

<button
  type="button"
  class="dev-badge"
  title={`${label} — нажмите, чтобы скопировать`}
  aria-label={`Бейдж страницы ${id}${task ? `: ${task}` : ''}`}
  data-dev-page-badge={id}
  onclick={copy}
>
  <span class="label">DEV</span>
  <span class="id">{id}</span>
  {#if task}
    <span class="task">{task}</span>
  {/if}
  {#if copied}
    <span class="hint">скопировано</span>
  {/if}
</button>

<style>
  .dev-badge {
    position: fixed;
    right: 12px;
    bottom: calc(12px + env(safe-area-inset-bottom));
    z-index: 60;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    max-width: min(420px, calc(100vw - 24px));
    padding: 4px 10px;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 999px;
    background: rgba(23, 23, 23, 0.9);
    color: #fff;
    font: 500 11px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    text-align: left;
    cursor: pointer;
    box-shadow: var(--shadow-md);
    backdrop-filter: blur(4px);
  }

  .dev-badge:hover {
    background: rgba(23, 23, 23, 0.98);
  }

  .label {
    color: #6ee7b7;
    letter-spacing: 0.04em;
  }

  .id {
    color: #93c5fd;
  }

  .task {
    overflow: hidden;
    color: rgba(255, 255, 255, 0.75);
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .hint {
    color: #fbbf24;
  }

  @media (max-width: 720px) {
    .dev-badge {
      bottom: calc(84px + env(safe-area-inset-bottom));
    }
  }
</style>
