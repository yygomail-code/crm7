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
    max-width: 480px;
    margin: 0 auto;
    padding: var(--space-6);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    text-align: center;
  }
</style>
