<script lang="ts">
  import { onMount } from 'svelte';
  import { ApiError } from '../lib/api/client';
  import { createRequest, listClients } from '../lib/api/requests';
  import type { ClientItem } from '../lib/api/types';
  import { auth } from '../lib/stores/auth.svelte';
  import { cart } from '../lib/stores/cart.svelte';
  import { router } from '../lib/router.svelte';
  import Button from '../lib/components/ui/Button.svelte';
  import Input from '../lib/components/ui/Input.svelte';

  let subject = $state('');
  let body = $state('');
  let priority = $state(2);
  let clientId = $state(0);
  let clients = $state<ClientItem[]>([]);
  let error = $state('');
  let loading = $state(false);

  const canPickClient = $derived(auth.can('clients.view.all') || auth.can('clients.view.own'));

  onMount(() => {
    if (canPickClient) {
      void listClients()
        .then((data) => {
          clients = data.items;
        })
        .catch(() => {
          clients = [];
        });
    }
  });

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    error = '';
    loading = true;

    try {
      const data = await createRequest({
        subject: subject.trim(),
        body: body.trim(),
        priority,
        client_id: clientId > 0 ? clientId : undefined,
        items: cart.items.map((item) => ({
          warehouse_id: item.warehouseId,
          warehouse_name: item.warehouseName,
          name: item.name,
          unit: item.unit,
          quantity: item.quantity
        }))
      });

      cart.clear();
      router.navigate(`/requests/${data.request.id}`);
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось создать заявку';
    } finally {
      loading = false;
    }
  }
</script>

<section class="page">
  <button type="button" class="back" onclick={() => router.navigate('/requests')}>
    ← К списку заявок
  </button>

  <div class="card">
    <h1>Новая заявка</h1>

    <form class="form" onsubmit={submit}>
      {#if canPickClient && clients.length > 0}
        <label class="field">
          <span class="label">Клиент</span>
          <select bind:value={clientId}>
            <option value={0}>Я (моя заявка)</option>
            {#each clients as client}
              <option value={client.id}>{client.name}{client.company ? ` — ${client.company}` : ''}</option>
            {/each}
          </select>
        </label>
      {/if}

      {#if cart.items.length > 0}
        <div class="cart-block">
          <div class="cart-head">
            <span class="cart-title">Позиции со склада ({cart.items.length})</span>
            <button type="button" class="cart-clear" onclick={() => cart.clear()}>Очистить</button>
          </div>
          <ul class="cart-list">
            {#each cart.items as item, index (item.name + item.warehouseId)}
              <li>
                <span class="cart-name">{item.name}</span>
                <span class="cart-qty">
                  <button
                    type="button"
                    class="qty-step"
                    aria-label={`Уменьшить: ${item.name}`}
                    onclick={() => cart.decreaseAt(item.warehouseId, item.name)}
                  >
                    −
                  </button>
                  <span class="qty-value">
                    {item.quantity.toLocaleString('ru-RU', { maximumFractionDigits: 3 })}
                    {item.unit}
                  </span>
                  <button
                    type="button"
                    class="qty-step"
                    aria-label={`Увеличить: ${item.name}`}
                    onclick={() => cart.increaseAt(item.warehouseId, item.name)}
                  >
                    +
                  </button>
                </span>
                <span class="cart-wh">{item.warehouseName}</span>
                <button
                  type="button"
                  class="cart-remove"
                  aria-label={`Убрать ${item.name}`}
                  onclick={() => cart.remove(index)}
                >
                  ×
                </button>
              </li>
            {/each}
          </ul>
        </div>
      {/if}

      <Input label="Тема" bind:value={subject} placeholder="Коротко: что нужно" />

      <label class="field">
        <span class="label">Описание</span>
        <textarea bind:value={body} rows="5" placeholder="Детали: объект, сроки, количество"></textarea>
      </label>

      <label class="field">
        <span class="label">Приоритет</span>
        <select bind:value={priority}>
          <option value={1}>Низкий</option>
          <option value={2}>Обычный</option>
          <option value={3}>Высокий</option>
        </select>
      </label>

      {#if error}
        <div class="alert">{error}</div>
      {/if}

      <Button type="submit" loading={loading} disabled={subject.trim().length < 3}>
        Создать заявку
      </Button>
    </form>
  </div>
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    max-width: 640px;
  }

  .back {
    align-self: flex-start;
    padding: 0;
    border: none;
    background: none;
    color: var(--primary);
    cursor: pointer;
    font-size: 14px;
  }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--space-5);
    box-shadow: var(--shadow-sm);
  }

  h1 {
    font-size: 20px;
  }

  .form {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
  }

  .field {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
  }

  .cart-block {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    padding: var(--space-3);
    border: 1px solid color-mix(in srgb, var(--primary) 30%, var(--border));
    border-radius: var(--radius-sm);
    background: color-mix(in srgb, var(--primary) 5%, white);
  }

  .cart-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .cart-title {
    font-size: 13px;
    font-weight: 600;
  }

  .cart-clear {
    border: none;
    background: none;
    color: var(--muted);
    cursor: pointer;
    font: inherit;
    font-size: 12px;
  }

  .cart-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .cart-list li {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: 14px;
  }

  .cart-name {
    flex: 1;
    min-width: 0;
  }

  .cart-qty {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
    color: var(--muted);
    font-size: 13px;
  }

  .qty-step {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface);
    color: var(--text);
    font-size: 14px;
    line-height: 1;
    cursor: pointer;
  }

  .qty-step:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .qty-value {
    min-width: 58px;
    text-align: center;
    color: var(--text);
    font-size: 13px;
    font-weight: 500;
  }

  .cart-wh {
    color: var(--muted);
    font-size: 12px;
    white-space: nowrap;
  }

  .cart-remove {
    border: none;
    background: none;
    color: var(--muted);
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
    padding: 0 4px;
  }

  .cart-remove:hover {
    color: var(--danger);
  }

  .label {
    font-size: 13px;
    color: var(--muted);
  }

  select,
  textarea {
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    font-family: inherit;
    font-size: inherit;
    outline: none;
    resize: vertical;
  }

  select:focus,
  textarea:focus {
    border-color: var(--primary);
  }

  .alert {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--danger);
    font-size: 13px;
  }
</style>
