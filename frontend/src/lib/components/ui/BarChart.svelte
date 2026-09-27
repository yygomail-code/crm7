<script lang="ts">
  interface Props {
    points: { label: string; value: number }[];
    empty?: string;
  }

  let { points, empty = 'Нет данных за период' }: Props = $props();

  const max = $derived(Math.max(1, ...points.map((point) => point.value)));
  const step = $derived(Math.max(1, Math.ceil(points.length / 10)));
  const showValues = $derived(points.length <= 20);

  function shortLabel(label: string): string {
    const parts = label.split('-');

    return parts.length === 3 ? `${parts[2]}.${parts[1]}` : label;
  }
</script>

{#if points.length === 0}
  <div class="empty">{empty}</div>
{:else}
  <div class="chart-wrap">
    <div class="axis">
      <span>{max}</span>
      <span>0</span>
    </div>

    <div class="chart">
      {#each points as point, index (point.label)}
        <div class="col" title={`${point.label}: ${point.value}`}>
          <div class="bar-area">
            <div class="bar" style={`height: ${Math.max(2, Math.round((point.value / max) * 100))}%`}></div>
          </div>
          <span class="value">{showValues || point.value > 0 ? point.value : ''}</span>
          <span class="label">{index % step === 0 || index === points.length - 1 ? shortLabel(point.label) : ''}</span>
        </div>
      {/each}
    </div>
  </div>
{/if}

<style>
  .chart-wrap {
    display: flex;
    gap: var(--space-2);
  }

  .axis {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    height: 120px;
    padding-top: var(--space-2);
    font-size: 10px;
    color: var(--muted);
    text-align: right;
  }

  .chart {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: stretch;
    gap: 3px;
    padding-top: var(--space-2);
  }

  .col {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
  }

  .bar-area {
    display: flex;
    align-items: flex-end;
    justify-content: center;
    width: 100%;
    height: 120px;
  }

  .bar {
    width: 100%;
    max-width: 34px;
    border-radius: 3px 3px 0 0;
    background: color-mix(in srgb, var(--primary) 75%, white);
  }

  .col:hover .bar {
    background: var(--primary);
  }

  .value {
    font-size: 10px;
    color: var(--muted);
  }

  .label {
    font-size: 10px;
    color: var(--muted);
    white-space: nowrap;
  }

  .empty {
    padding: var(--space-4);
    color: var(--muted);
    font-size: 13px;
    text-align: center;
  }
</style>
