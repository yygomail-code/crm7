<script lang="ts">
  interface Props {
    title: string;
    color?: string;
  }

  let { title, color = '#6b7280' }: Props = $props();
</script>

<span class="badge" style="--badge-color: {color}">{title}</span>

<style>
  .badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 500;
    color: var(--badge-color);
    background: color-mix(in srgb, var(--badge-color) 12%, white);
    border: 1px solid color-mix(in srgb, var(--badge-color) 30%, white);
    white-space: nowrap;
  }
</style>
