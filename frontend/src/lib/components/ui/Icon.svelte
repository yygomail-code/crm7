<script lang="ts">
  interface Props {
    name: string;
    size?: number;
  }

  let { name, size = 20 }: Props = $props();
</script>

<svg
  viewBox="0 0 24 24"
  width={size}
  height={size}
  fill="none"
  stroke="currentColor"
  stroke-width="1.8"
  stroke-linecap="round"
  stroke-linejoin="round"
  aria-hidden="true"
>
  {#if name === 'stocks'}
    <path d="M21 8 12 3 3 8v8l9 5 9-5V8Z" />
    <path d="m3 8 9 5 9-5" />
    <path d="M12 13v8" />
  {:else if name === 'edit'}
    <path d="M4 20h4L19.5 8.5a2.12 2.12 0 0 0-3-3L5 17v3Z" />
    <path d="m14.5 6.5 3 3" />
  {:else if name === 'requests'}
    <path d="M8 6h13" />
    <path d="M8 12h13" />
    <path d="M8 18h13" />
    <path d="M3.5 6h.01" />
    <path d="M3.5 12h.01" />
    <path d="M3.5 18h.01" />
  {:else if name === 'reports'}
    <path d="M3 3v18h18" />
    <path d="M7.5 15.5v-4" />
    <path d="M12 15.5V7" />
    <path d="M16.5 15.5v-6" />
  {:else if name === 'my-reports'}
    <circle cx="12" cy="12" r="9" />
    <path d="M12 3v9h9" />
  {:else if name === 'clients'}
    <circle cx="9" cy="8" r="3.5" />
    <path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5" />
    <path d="M16.2 4.8a3.5 3.5 0 0 1 0 6.4" />
    <path d="M18 14.9c2.2.6 3.5 2.2 3.5 5.1" />
  {:else if name === 'substitutions'}
    <path d="M7 20V5" />
    <path d="m3.5 8.5 3.5-3.5 3.5 3.5" />
    <path d="M17 4v15" />
    <path d="m20.5 15.5-3.5 3.5-3.5-3.5" />
  {:else if name === 'admin'}
    <path d="M12 3 5 6v5.5c0 4.3 3 7.9 7 9.5 4-1.6 7-5.2 7-9.5V6Z" />
    <path d="m9.3 11.8 1.9 1.9 3.6-3.8" />
  {:else if name === 'trash'}
    <path d="M4 7h16" />
    <path d="M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7" />
    <path d="M6.5 7l.8 12a2 2 0 0 0 2 1.9h5.4a2 2 0 0 0 2-1.9L17.5 7" />
    <path d="M10 11v6" />
    <path d="M14 11v6" />
  {:else if name === 'settings'}
    <path d="M4 7h16" />
    <path d="M4 12h16" />
    <path d="M4 17h16" />
    <circle cx="9" cy="7" r="2" />
    <circle cx="15" cy="12" r="2" />
    <circle cx="8" cy="17" r="2" />
  {:else if name === 'sort'}
    <path d="M7 4v16" />
    <path d="m3.5 7.5 3.5-3.5 3.5 3.5" />
    <path d="M17 20V4" />
    <path d="m13.5 16.5 3.5 3.5 3.5-3.5" />
  {:else if name === 'group'}
    <path d="M12 3 3 7.5 12 12l9-4.5L12 3Z" />
    <path d="m3 12 9 4.5 9-4.5" />
    <path d="m3 16.5 9 4.5 9-4.5" />
  {:else if name === 'help'}
    <circle cx="12" cy="12" r="9" />
    <path d="M9.5 9.3a2.6 2.6 0 0 1 4.7 1.6c0 1.7-2.2 2.1-2.2 3.6" />
    <path d="M12 17.6h.01" />
  {:else if name === 'export'}
    <path d="M12 3v11" />
    <path d="m7.5 9.5 4.5 4.5 4.5-4.5" />
    <path d="M4.5 20h15" />
  {:else if name === 'view'}
    <path d="M4 4h7v7H4z" />
    <path d="M13 4h7v7h-7z" />
    <path d="M4 13h7v7H4z" />
    <path d="M13 13h7v7h-7z" />
  {:else if name === 'camera'}
    <path d="M3 8.5A1.5 1.5 0 0 1 4.5 7H7l1.4-2h7.2L17 7h2.5A1.5 1.5 0 0 1 21 8.5v9A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5v-9Z" />
    <circle cx="12" cy="13" r="3" />
  {:else if name === 'arrow-up'}
    <path d="M12 19V5" />
    <path d="m6 11 6-6 6 6" />
  {:else if name === 'arrow-down'}
    <path d="M12 5v14" />
    <path d="m6 13 6 6 6-6" />
  {:else if name === 'cart'}
    <path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L21 7H6" />
    <circle cx="10" cy="20" r="1" />
    <circle cx="18" cy="20" r="1" />
  {/if}
</svg>
