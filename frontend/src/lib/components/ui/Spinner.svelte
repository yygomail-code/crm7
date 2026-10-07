<script lang="ts">
  interface Props {
    size?: number;
  }

  let { size = 18 }: Props = $props();
</script>

<span class="spinner" style="--size: {size}px" aria-hidden="true"></span>

<style>
  .spinner {
    display: inline-block;
    width: var(--size);
    height: var(--size);
    border: 2px solid rgba(22, 119, 255, 0.25);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
  }

  @keyframes spin {
    to {
      transform: rotate(360deg);
    }
  }
</style>
