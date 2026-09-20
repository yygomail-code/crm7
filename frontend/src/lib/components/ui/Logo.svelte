<script lang="ts">
  interface Props {
    size?: number;
  }

  let { size = 28 }: Props = $props();
</script>

<svg viewBox="0 0 64 64" width={size} height={size} role="img" aria-label="CRM">
  <defs>
    <linearGradient id="crm-logo-gradient" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#3b82f6" />
      <stop offset="1" stop-color="#1d4ed8" />
    </linearGradient>
  </defs>
  <rect width="64" height="64" rx="14" fill="url(#crm-logo-gradient)" />
  <path
    d="M20 18h24a6 6 0 0 1 6 6v12a6 6 0 0 1-6 6H32l-9 8v-8h-3a6 6 0 0 1-6-6V24a6 6 0 0 1 6-6z"
    fill="#ffffff"
  />
  <path
    d="M24 30l5 5 11-11"
    fill="none"
    stroke="#2563eb"
    stroke-width="4.5"
    stroke-linecap="round"
    stroke-linejoin="round"
  />
</svg>
