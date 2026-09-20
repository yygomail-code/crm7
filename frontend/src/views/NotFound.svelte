<script lang="ts">
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
</script>

<section class="page">
  <h1>Страница не найдена</h1>
  <p>Такого раздела пока нет.</p>
  <Button variant="ghost" onclick={() => router.navigate('/requests')}>К заявкам</Button>
</section>

<style>
  .page {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--space-6);
    box-shadow: var(--shadow-sm);
  }
</style>
