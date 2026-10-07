<script lang="ts">
  import { ApiError } from '../lib/api/client';
  import { previewRequestPrices, type CreateRequestItem } from '../lib/api/requests';
  import { appSettings } from '../lib/stores/app-settings.svelte';
  import { cart } from '../lib/stores/cart.svelte';
  import { router } from '../lib/router.svelte';
  import { formatPrice } from '../lib/format';
  import Button from '../lib/components/ui/Button.svelte';
  import Icon from '../lib/components/ui/Icon.svelte';

  let priceType = $state<{ id: number; title: string } | null>(null);
  let refreshing = $state(false);
  let error = $state('');

  const pricesEnabled = $derived(appSettings.pricesEnabled);

  function payload(): CreateRequestItem[] {
    return cart.items.map((item) => ({
      warehouse_id: item.warehouseId,
      warehouse_name: item.warehouseName,
      stock_level_id: item.stockLevelId ?? null,
      name: item.name,
      unit: item.unit,
      quantity: item.quantity
    }));
  }

  async function refreshPrices(): Promise<void> {
    if (!pricesEnabled || cart.items.length === 0) {
      return;
    }

    refreshing = true;
    error = '';

    try {
      const preview = await previewRequestPrices(payload());

      if (preview.enabled) {
        priceType = preview.type;
        cart.applyPrices(preview.items.map((row) => row.price));
      }
    } catch (cause) {
      error = cause instanceof ApiError ? cause.message : 'Не удалось обновить цены';
    } finally {
      refreshing = false;
    }
  }

  $effect(() => {
    void appSettings.pricesEnabled;
    void cart.items.length;

    void refreshPrices();
  });

  function rowSum(index: number): number | null {
    const item = cart.items[index];

    return item && item.price !== null && item.price !== undefined
      ? Number((item.price * item.quantity).toFixed(2))
      : null;
  }

  function clearCart(): void {
    if (confirm('Очистить корзину?')) {
      cart.clear();
      priceType = null;
    }
  }

  function checkout(): void {
    router.navigate('/requests/new?from=cart');
  }
</script>

<section class="page">
  <div class="head">
    <h1>Корзина</h1>
    {#if cart.count > 0}
      <span class="count">{cart.count} поз.</span>
    {/if}
  </div>

  {#if error}
    <div class="alert">{error}</div>
  {/if}

  {#if cart.items.length === 0}
    <div class="card empty">
      <Icon name="cart" size={36} />
      <h2>Корзина пуста</h2>
      <p class="muted">
        Добавляйте позиции из раздела «Номенклатура» — из корзины можно оформить заявку.
      </p>
      <Button variant="ghost" onclick={() => router.navigate('/stocks')}>
        Перейти к остаткам
      </Button>
    </div>
  {:else}
    <div class="card">
      <div class="rows">
        {#each cart.items as item, index (item.warehouseId + '|' + item.name)}
          <div class="row">
            <div class="info">
              <span class="name">{item.name}</span>
              {#if item.warehouseName}<span class="wh">{item.warehouseName}</span>{/if}
            </div>

            <div class="qty-box">
              <button
                type="button"
                class="step"
                aria-label={`Уменьшить: ${item.name}`}
                onclick={() => cart.decreaseAt(item.warehouseId, item.name)}
              >
                −
              </button>
              <span class="qty" aria-label={`Количество: ${item.name}`}>
                {item.quantity.toLocaleString('ru-RU', { maximumFractionDigits: 3 })}
              </span>
              <button
                type="button"
                class="step"
                aria-label={`Увеличить: ${item.name}`}
                onclick={() => cart.increaseAt(item.warehouseId, item.name)}
              >
                +
              </button>
              {#if item.unit}<span class="unit">{item.unit}</span>{/if}
            </div>

            {#if pricesEnabled}
              <span class="price">{formatPrice(item.price ?? null)}</span>
              <span class="sum">{formatPrice(rowSum(index))}</span>
            {/if}

            <button
              type="button"
              class="del"
              aria-label={`Убрать: ${item.name}`}
              title="Убрать из корзины"
              onclick={() => cart.remove(index)}
            >
              ✕
            </button>
          </div>
        {/each}
      </div>

      <div class="foot">
        {#if pricesEnabled}
          <div class="totals">
            <span class="muted">
              Цены: {priceType?.title ?? (refreshing ? 'обновление…' : '—')}
              {#if cart.missingPriceCount > 0}
                · у {cart.missingPriceCount} поз. цена не задана
              {/if}
            </span>
            <span class="total">Итого: {formatPrice(cart.total)}</span>
          </div>
        {/if}

        <div class="actions">
          <Button variant="ghost" onclick={clearCart}>Очистить корзину</Button>
          <Button disabled={!appSettings.salesEnabled} onclick={checkout}>Оформить заявку</Button>
        </div>

        {#if !appSettings.salesEnabled}
          <p class="muted">Продажи отключены — оформление заявок недоступно.</p>
        {/if}
      </div>
    </div>
  {/if}
</section>

<style>
  .page {
    display: flex;
    flex-direction: column;
    gap: var(--space-4);
    max-width: 860px;
    margin: 0 auto;
  }

  .head {
    display: flex;
    align-items: baseline;
    gap: var(--space-2);
  }

  h1 {
    margin: 0;
    font-size: 20px;
  }

  .count {
    color: var(--muted);
    font-size: 14px;
  }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--space-5);
    box-shadow: var(--shadow-sm);
  }

  .empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-6) var(--space-5);
    color: var(--muted);
    text-align: center;
  }

  .empty h2 {
    margin: 0;
    color: var(--text);
    font-size: 17px;
  }

  .muted {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
  }

  .rows {
    display: flex;
    flex-direction: column;
  }

  .row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
  }

  .info {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 0;
  }

  .name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .wh {
    color: var(--muted);
    font-size: 12px;
  }

  .qty-box {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .qty {
    min-width: 52px;
    text-align: center;
    font-weight: 500;
  }

  .unit {
    color: var(--muted);
    font-size: 13px;
  }

  .price,
  .sum {
    min-width: 96px;
    text-align: right;
    white-space: nowrap;
  }

  .sum {
    font-weight: 600;
  }

  .step {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface);
    color: var(--text);
    font-size: 15px;
    line-height: 1;
    cursor: pointer;
  }

  .step:hover {
    border-color: var(--primary);
    color: var(--primary);
  }

  .del {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface);
    color: var(--muted);
    font-size: 13px;
    line-height: 1;
    cursor: pointer;
  }

  .del:hover {
    border-color: var(--danger);
    color: var(--danger);
  }

  .foot {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    margin-top: var(--space-4);
  }

  .totals {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-2);
  }

  .total {
    font-size: 17px;
    font-weight: 700;
  }

  .actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--space-2);
  }

  .alert {
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    background: var(--danger-bg);
    color: var(--danger);
    font-size: 13px;
  }

  @media (max-width: 640px) {
    .row {
      flex-wrap: wrap;
    }

    .price,
    .sum {
      min-width: 0;
    }
  }
</style>
